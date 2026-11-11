<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AllocationMethod;
use App\Enums\TripRole;
use App\Enums\TripVisibility;
use App\Models\Expense;
use App\Models\ExpenseSplit;
use App\Models\Trip;
use App\Services\AuditLogger;
use App\Services\ExpenseAllocationCalculator;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ExpenseController
{
    public function index(Request $request, Trip $trip)
    {
        $this->authorizeMember($request, $trip);

        return $trip->expenses()
            ->with(['payer:id,name', 'splits.user:id,name', 'comments.user:id,name'])
            ->latest('spent_at')
            ->paginate(30);
    }

    public function store(
        Request $request,
        Trip $trip,
        ExpenseAllocationCalculator $allocationCalculator,
        AuditLogger $audit,
    ) {
        $member = $this->authorizeMember($request, $trip);
        abort_if($member->role === TripRole::VIEWER->value, 403, 'Viewers cannot create expenses.');

        $methods = array_map(static fn (AllocationMethod $method): string => $method->value, AllocationMethod::cases());
        $data = $request->validate([
            'payer_id' => 'required|integer|min:1',
            'description' => 'required|string|max:180',
            'category' => 'required|in:accommodation,transport,food,activities,shopping,emergency,other',
            'amount_minor' => 'required|integer|min:1|max:9000000000000000',
            'currency' => 'required|string|size:3',
            'allocation_method' => ['required', 'string', 'in:'.implode(',', $methods)],
            'spent_at' => 'required|date',
            'participants' => 'required|array|min:1|max:500',
            'participants.*.user_id' => 'required|integer|min:1|distinct',
            'participants.*.share_minor' => 'required_if:allocation_method,manual|integer|min:0|max:9000000000000000',
            'participants.*.percentage_basis_points' => 'required_if:allocation_method,percentage|integer|min:0|max:10000',
            'participants.*.shares' => 'required_if:allocation_method,shares|integer|min:1|max:1000000',
            'participants.*.comment' => 'nullable|string|max:1000',
            'comment' => 'nullable|string|max:2000',
            'idempotency_key' => 'nullable|uuid',
        ]);

        if (strtoupper($data['currency']) !== strtoupper($trip->currency)) {
            throw ValidationException::withMessages(['currency' => ['Expense currency must match the trip currency.']]);
        }

        $activeMemberIds = $trip->members()->where('status', 'active')->pluck('user_id')->map(static fn ($id): int => (int) $id)->all();
        if (! in_array((int) $data['payer_id'], $activeMemberIds, true)) {
            throw ValidationException::withMessages(['payer_id' => ['Payer must be an active trip member.']]);
        }

        $participantIds = array_map(static fn (array $participant): int => (int) $participant['user_id'], $data['participants']);
        foreach ($participantIds as $participantId) {
            if (! in_array($participantId, $activeMemberIds, true)) {
                throw ValidationException::withMessages(['participants' => ['Every participant must be an active trip member.']]);
            }
        }

        $method = $data['allocation_method'];
        $allocationInput = match ($method) {
            AllocationMethod::EQUAL->value => $participantIds,
            AllocationMethod::MANUAL->value => $this->participantValues($data['participants'], 'share_minor'),
            AllocationMethod::PERCENTAGE->value => $this->participantValues($data['participants'], 'percentage_basis_points'),
            AllocationMethod::SHARES->value => $this->participantValues($data['participants'], 'shares'),
        };

        try {
            $allocatedShares = $allocationCalculator->calculate($method, (int) $data['amount_minor'], $allocationInput);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['participants' => [$exception->getMessage()]]);
        }

        $fingerprint = hash('sha256', json_encode([
            'payer_id' => (int) $data['payer_id'], 'description' => $data['description'], 'category' => $data['category'],
            'amount_minor' => (int) $data['amount_minor'], 'currency' => strtoupper($data['currency']),
            'allocation_method' => $method, 'spent_at' => $data['spent_at'], 'participants' => $data['participants'],
            'comment' => $data['comment'] ?? null,
        ], JSON_THROW_ON_ERROR));
        $existing = ! empty($data['idempotency_key']) ? $trip->expenses()->where('idempotency_key', $data['idempotency_key'])->first() : null;
        if ($existing) {
            if (! $existing->idempotency_fingerprint || ! hash_equals($existing->idempotency_fingerprint, $fingerprint)) {
                throw ValidationException::withMessages(['idempotency_key' => ['This idempotency key was already used with a different request.']]);
            }

            return response()->json($existing->load(['splits.user:id,name', 'payer:id,name']), 200);
        }

        try {
            return DB::transaction(function () use ($request, $trip, $data, $allocatedShares, $audit, $fingerprint): mixed {
                $lockedTrip = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
                $actorMembership = $lockedTrip->members()->where('user_id', $request->user()->id)->where('status', 'active')->lockForUpdate()->first();
                abort_unless($actorMembership, 403);
                abort_if($actorMembership->role === 'viewer', 403, 'Viewers cannot create expenses.');
                if ($lockedTrip->visibility === TripVisibility::PRIVATE && (! $actorMembership->last_private_verified_at || $actorMembership->last_private_verified_at->lt(now()->subMinutes((int) config('splittrip.private_access_minutes', 30))))) {
                    abort(423, 'Private-trip OTP required.');
                }
                $activeIds = $lockedTrip->members()->where('status', 'active')->lockForUpdate()->pluck('user_id')->map(static fn ($id) => (int) $id)->all();
                if (! in_array((int) $data['payer_id'], $activeIds, true)) {
                    throw ValidationException::withMessages(['payer_id' => ['Payer must still be an active trip member.']]);
                }
                foreach ($data['participants'] as $participant) {
                    if (! in_array((int) $participant['user_id'], $activeIds, true)) {
                        throw ValidationException::withMessages(['participants' => ['Every participant must still be an active trip member.']]);
                    }
                }
                $lockedTrip->settlements()->where('status', 'proposed')->update(['status' => 'superseded']);
                $expense = Expense::create([
                    'trip_id' => $trip->id,
                    'payer_id' => $data['payer_id'],
                    'created_by' => $request->user()->id,
                    'description' => $data['description'],
                    'category' => $data['category'],
                    'amount_minor' => $data['amount_minor'],
                    'currency' => strtoupper($data['currency']),
                    'allocation_method' => $data['allocation_method'],
                    'spent_at' => $data['spent_at'],
                    'idempotency_key' => $data['idempotency_key'] ?? null,
                    'idempotency_fingerprint' => $fingerprint,
                ]);

                foreach ($data['participants'] as $participant) {
                    $userId = (int) $participant['user_id'];
                    ExpenseSplit::create([
                        'expense_id' => $expense->id,
                        'user_id' => $userId,
                        'share_minor' => $allocatedShares[$userId],
                        'percentage_basis_points' => $participant['percentage_basis_points'] ?? null,
                        'shares' => $participant['shares'] ?? null,
                        'comment' => $participant['comment'] ?? null,
                    ]);
                }

                if (! empty($data['comment'])) {
                    $expense->comments()->create(['user_id' => $request->user()->id, 'body' => $data['comment']]);
                }

                $audit->record($trip, $request->user(), 'expense.created', 'expense', $expense->id, [
                    'amount_minor' => $expense->amount_minor,
                    'currency' => $expense->currency,
                    'allocation_method' => $expense->allocation_method->value,
                ]);

                return response()->json($expense->load(['splits.user:id,name', 'payer:id,name']), 201);
            });
        } catch (QueryException $exception) {
            // PostgreSQL marks a transaction failed after a unique violation. Resolve the
            // idempotency replay outside that transaction, never inside the failed one.
            if (! empty($data['idempotency_key'])) {
                $existing = $trip->expenses()->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    if (! $existing->idempotency_fingerprint || ! hash_equals($existing->idempotency_fingerprint, $fingerprint)) {
                        throw ValidationException::withMessages(['idempotency_key' => ['This idempotency key was already used with a different request.']]);
                    }

                    return response()->json($existing->load(['splits.user:id,name', 'payer:id,name']), 200);
                }
            }
            throw $exception;
        }
    }

    public function show(Request $request, Trip $trip, Expense $expense)
    {
        $this->authorizeMember($request, $trip);
        abort_unless((int) $expense->trip_id === (int) $trip->id, 404);

        return response()->json($expense->load(['payer:id,name', 'splits.user:id,name', 'comments.user:id,name']));
    }

    public function update(Request $request, Trip $trip, Expense $expense, ExpenseAllocationCalculator $calculator, AuditLogger $audit)
    {
        $member = $this->authorizeMember($request, $trip);
        abort_unless((int) $expense->trip_id === (int) $trip->id, 404);
        abort_if($member->role === TripRole::VIEWER->value, 403, 'Viewers cannot edit expenses.');
        abort_unless((int) $expense->created_by === (int) $request->user()->id || in_array($member->role, ['owner', 'admin'], true), 403);
        if ($expense->splits()->where('settled_minor', '>', 0)->exists() || $this->hasConfirmedSettlementSnapshot($expense)) {
            throw ValidationException::withMessages(['expense' => ['This expense is part of settlement history and cannot be edited. Create an adjustment expense instead.']]);
        }
        $data = $request->validate([
            'payer_id' => 'required|integer|min:1', 'description' => 'required|string|max:180',
            'category' => 'required|in:accommodation,transport,food,activities,shopping,emergency,other',
            'amount_minor' => 'required|integer|min:1|max:9000000000000000', 'currency' => 'required|string|size:3',
            'allocation_method' => 'required|string|in:equal,manual,percentage,shares', 'spent_at' => 'required|date',
            'participants' => 'required|array|min:1|max:500', 'participants.*.user_id' => 'required|integer|min:1|distinct',
            'participants.*.share_minor' => 'required_if:allocation_method,manual|integer|min:0|max:9000000000000000',
            'participants.*.percentage_basis_points' => 'required_if:allocation_method,percentage|integer|min:0|max:10000',
            'participants.*.shares' => 'required_if:allocation_method,shares|integer|min:1|max:1000000',
            'participants.*.comment' => 'nullable|string|max:1000',
        ]);
        if (strtoupper($data['currency']) !== strtoupper($trip->currency)) {
            throw ValidationException::withMessages(['currency' => ['Expense currency must match the trip currency.']]);
        }
        $activeIds = $trip->members()->where('status', 'active')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $ids = array_map(fn ($p) => (int) $p['user_id'], $data['participants']);
        if (! in_array((int) $data['payer_id'], $activeIds, true)) {
            throw ValidationException::withMessages(['payer_id' => ['Payer must be an active trip member.']]);
        }
        foreach ($ids as $id) {
            if (! in_array($id, $activeIds, true)) {
                throw ValidationException::withMessages(['participants' => ['Every participant must be an active trip member.']]);
            }
        }
        $input = match ($data['allocation_method']) {
            'equal' => $ids, 'manual' => $this->participantValues($data['participants'], 'share_minor'),
            'percentage' => $this->participantValues($data['participants'], 'percentage_basis_points'),
            'shares' => $this->participantValues($data['participants'], 'shares'),
        };
        try {
            $shares = $calculator->calculate($data['allocation_method'], (int) $data['amount_minor'], $input);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['participants' => [$e->getMessage()]]);
        }

        return DB::transaction(function () use ($request, $trip, $expense, $data, $shares, $audit) {
            $lockedTrip = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $actorMembership = $lockedTrip->members()->where('user_id', $request->user()->id)->where('status', 'active')->lockForUpdate()->first();
            abort_unless($actorMembership, 403);
            abort_if($actorMembership->role === 'viewer', 403, 'Viewers cannot edit expenses.');
            abort_unless((int) $expense->created_by === (int) $request->user()->id || in_array($actorMembership->role, ['owner', 'admin'], true), 403);
            if ($lockedTrip->visibility === TripVisibility::PRIVATE && (! $actorMembership->last_private_verified_at || $actorMembership->last_private_verified_at->lt(now()->subMinutes((int) config('splittrip.private_access_minutes', 30))))) {
                abort(423, 'Private-trip OTP required.');
            }
            $locked = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
            $activeIds = $lockedTrip->members()->where('status', 'active')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            if (! in_array((int) $data['payer_id'], $activeIds, true)) {
                throw ValidationException::withMessages(['payer_id' => ['Payer must still be an active trip member.']]);
            }
            foreach ($data['participants'] as $participant) {
                if (! in_array((int) $participant['user_id'], $activeIds, true)) {
                    throw ValidationException::withMessages(['participants' => ['Every participant must still be an active trip member.']]);
                }
            }
            if ($locked->splits()->where('settled_minor', '>', 0)->exists() || $this->hasConfirmedSettlementSnapshot($locked)) {
                throw ValidationException::withMessages(['expense' => ['Expenses included in confirmed settlement history cannot be changed.']]);
            }
            $before = ['amount_minor' => (int) $locked->amount_minor, 'payer_id' => (int) $locked->payer_id];
            $locked->update(['payer_id' => $data['payer_id'], 'description' => $data['description'], 'category' => $data['category'], 'amount_minor' => $data['amount_minor'], 'currency' => strtoupper($data['currency']), 'allocation_method' => $data['allocation_method'], 'spent_at' => $data['spent_at']]);
            $locked->splits()->delete();
            foreach ($data['participants'] as $participant) {
                $uid = (int) $participant['user_id'];
                $locked->splits()->create(['user_id' => $uid, 'share_minor' => $shares[$uid], 'percentage_basis_points' => $participant['percentage_basis_points'] ?? null, 'shares' => $participant['shares'] ?? null, 'comment' => $participant['comment'] ?? null]);
            }
            $lockedTrip->settlements()->where('status', 'proposed')->update(['status' => 'superseded']);
            $audit->record($lockedTrip, $request->user(), 'expense.updated', 'expense', (int) $locked->id, ['before' => $before, 'after_amount_minor' => (int) $locked->amount_minor]);

            return response()->json($locked->fresh()->load(['payer:id,name', 'splits.user:id,name']));
        });
    }

    public function destroy(Request $request, Trip $trip, Expense $expense, AuditLogger $audit)
    {
        $member = $this->authorizeMember($request, $trip);
        abort_unless((int) $expense->trip_id === (int) $trip->id, 404);
        abort_if($member->role === TripRole::VIEWER->value, 403, 'Viewers cannot delete expenses.');
        abort_unless((int) $expense->created_by === (int) $request->user()->id || in_array($member->role, ['owner', 'admin'], true), 403);
        if ($expense->splits()->where('settled_minor', '>', 0)->exists() || $this->hasConfirmedSettlementSnapshot($expense)) {
            throw ValidationException::withMessages(['expense' => ['This expense is part of confirmed settlement history and cannot be deleted.']]);
        }

        return DB::transaction(function () use ($request, $trip, $expense, $audit) {
            $lockedTrip = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $actorMembership = $lockedTrip->members()->where('user_id', $request->user()->id)->where('status', 'active')->lockForUpdate()->first();
            abort_unless($actorMembership, 403);
            abort_if($actorMembership->role === 'viewer', 403, 'Viewers cannot delete expenses.');
            abort_unless((int) $expense->created_by === (int) $request->user()->id || in_array($actorMembership->role, ['owner', 'admin'], true), 403);
            if ($lockedTrip->visibility === TripVisibility::PRIVATE && (! $actorMembership->last_private_verified_at || $actorMembership->last_private_verified_at->lt(now()->subMinutes((int) config('splittrip.private_access_minutes', 30))))) {
                abort(423, 'Private-trip OTP required.');
            }
            $locked = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
            $id = (int) $locked->id;
            if ($locked->splits()->where('settled_minor', '>', 0)->exists() || $this->hasConfirmedSettlementSnapshot($locked)) {
                throw ValidationException::withMessages(['expense' => ['This expense is part of confirmed settlement history and cannot be deleted.']]);
            }
            $locked->delete();
            $lockedTrip->settlements()->where('status', 'proposed')->update(['status' => 'superseded']);
            $audit->record($lockedTrip, $request->user(), 'expense.deleted', 'expense', $id, ['amount_minor' => (int) $locked->amount_minor]);

            return response()->noContent();
        });
    }

    private function hasConfirmedSettlementSnapshot(Expense $expense): bool
    {
        return DB::table('settlement_expense_snapshots')
            ->join('settlements', 'settlements.id', '=', 'settlement_expense_snapshots.settlement_id')
            ->where('settlement_expense_snapshots.expense_id', $expense->id)
            ->whereIn('settlements.status', ['confirmed', 'completed'])
            ->exists();
    }

    /** @param array<int,array<string,mixed>> $participants @return array<int,int> */
    private function participantValues(array $participants, string $field): array
    {
        $values = [];
        foreach ($participants as $participant) {
            if (! array_key_exists($field, $participant)) {
                throw ValidationException::withMessages(['participants' => ["Each participant must provide {$field} for this allocation method."]]);
            }
            $values[(int) $participant['user_id']] = (int) $participant[$field];
        }

        return $values;
    }

    private function authorizeMember(Request $request, Trip $trip)
    {
        $member = $trip->members()->where('user_id', $request->user()->id)->where('status', 'active')->first();
        abort_unless($member, 403);

        if ($trip->visibility === TripVisibility::PRIVATE
            && (! $member->last_private_verified_at
                || $member->last_private_verified_at->lt(now()->subMinutes((int) config('splittrip.private_access_minutes', 30))))) {
            abort(423, 'Private-trip OTP required.');
        }

        return $member;
    }
}
