<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TripVisibility;
use App\Models\Settlement;
use App\Models\SettlementTransfer;
use App\Models\Trip;
use App\Services\AuditLogger;
use App\Services\SettlementCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SettlementController
{
    public function preview(Request $request, Trip $trip, SettlementCalculator $calculator)
    {
        $this->authorizeMember($request, $trip);
        $ledger = $this->ledger($trip);

        return response()->json(['currency' => $trip->currency, 'balances_minor' => $ledger, 'transfers' => $calculator->transfers($ledger)]);
    }

    public function index(Request $request, Trip $trip)
    {
        $this->authorizeMember($request, $trip);

        return $trip->settlements()->with(['transfers.fromUser:id,name', 'transfers.toUser:id,name'])->latest()->paginate(20);
    }

    public function store(Request $request, Trip $trip, SettlementCalculator $calculator, AuditLogger $audit)
    {
        $member = $this->authorizeMember($request, $trip);
        abort_if($member->role === 'viewer', 403, 'Viewers cannot propose settlements.');

        return DB::transaction(function () use ($request, $trip, $calculator, $audit) {
            $lockedTrip = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $actorMembership = $lockedTrip->members()->where('user_id', $request->user()->id)->where('status', 'active')->lockForUpdate()->first();
            abort_unless($actorMembership, 403);
            abort_if($actorMembership->role === 'viewer', 403, 'Viewers cannot propose settlements.');
            if ($lockedTrip->visibility === TripVisibility::PRIVATE && (! $actorMembership->last_private_verified_at || $actorMembership->last_private_verified_at->lt(now()->subMinutes((int) config('splittrip.private_access_minutes', 30))))) {
                abort(423, 'Private-trip OTP required.');
            }
            $ledger = $this->ledger($lockedTrip);
            $transfers = $calculator->transfers($ledger);
            $lockedTrip->settlements()->where('status', 'proposed')->update(['status' => 'superseded']);
            $settlement = Settlement::create(['trip_id' => $lockedTrip->id, 'created_by' => $request->user()->id, 'currency' => $lockedTrip->currency, 'status' => 'proposed']);
            foreach ($this->unsettledExpenses($lockedTrip)->with('splits')->get() as $expense) {
                $settlement->expenseSnapshots()->create(['expense_id' => $expense->id, 'snapshot' => ['payer_id' => (int) $expense->payer_id, 'description' => $expense->description, 'category' => $expense->category, 'amount_minor' => (int) $expense->amount_minor, 'currency' => $expense->currency, 'spent_at' => $expense->spent_at?->toISOString(), 'splits' => $expense->splits->map(fn ($split) => ['user_id' => (int) $split->user_id, 'share_minor' => (int) $split->share_minor])->all()]]);
            }
            foreach ($transfers as $transfer) {
                $settlement->transfers()->create(['from_user_id' => $transfer['from'], 'to_user_id' => $transfer['to'], 'amount_minor' => $transfer['amount_minor'], 'status' => 'proposed']);
            }
            $audit->record($lockedTrip, $request->user(), 'settlement.proposed', 'settlement', (int) $settlement->id, ['transfer_count' => count($transfers)]);

            return response()->json($settlement->load(['transfers.fromUser:id,name', 'transfers.toUser:id,name']), 201);
        });
    }

    public function confirm(Request $request, Trip $trip, Settlement $settlement, AuditLogger $audit)
    {
        $this->authorizeManager($request, $trip);
        abort_unless((int) $settlement->trip_id === (int) $trip->id, 404);

        return DB::transaction(function () use ($request, $trip, $settlement, $audit) {
            $lockedTrip = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $actor = $lockedTrip->members()->where('user_id', $request->user()->id)->where('status', 'active')->lockForUpdate()->first();
            abort_unless($actor && in_array($actor->role, ['owner', 'admin'], true), 403);
            if ($lockedTrip->visibility === TripVisibility::PRIVATE && (! $actor->last_private_verified_at || $actor->last_private_verified_at->lt(now()->subMinutes((int) config('splittrip.private_access_minutes', 30))))) {
                abort(423, 'Private-trip OTP required.');
            }
            $locked = Settlement::whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'proposed') {
                throw ValidationException::withMessages(['status' => ['Only proposed settlements can be confirmed.']]);
            }
            $locked->update(['status' => $locked->transfers()->exists() ? 'confirmed' : 'completed', 'confirmed_at' => now()]);
            $audit->record($trip, $request->user(), 'settlement.confirmed', 'settlement', (int) $locked->id);

            return response()->json($locked->fresh()->load('transfers'));
        });
    }

    public function reject(Request $request, Trip $trip, Settlement $settlement, AuditLogger $audit)
    {
        $this->authorizeManager($request, $trip);
        abort_unless((int) $settlement->trip_id === (int) $trip->id, 404);

        return DB::transaction(function () use ($request, $trip, $settlement, $audit) {
            $lockedTrip = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $actor = $lockedTrip->members()->where('user_id', $request->user()->id)->where('status', 'active')->lockForUpdate()->first();
            abort_unless($actor && in_array($actor->role, ['owner', 'admin'], true), 403);
            if ($lockedTrip->visibility === TripVisibility::PRIVATE && (! $actor->last_private_verified_at || $actor->last_private_verified_at->lt(now()->subMinutes((int) config('splittrip.private_access_minutes', 30))))) {
                abort(423, 'Private-trip OTP required.');
            }
            $locked = Settlement::whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'proposed') {
                throw ValidationException::withMessages(['status' => ['Only proposed settlements can be rejected.']]);
            }
            $locked->update(['status' => 'rejected']);
            $audit->record($trip, $request->user(), 'settlement.rejected', 'settlement', (int) $locked->id);

            return response()->json($locked->fresh()->load('transfers'));
        });
    }

    public function markPaid(Request $request, Trip $trip, Settlement $settlement, SettlementTransfer $transfer, AuditLogger $audit)
    {
        $member = $this->authorizeMember($request, $trip);
        abort_if($member->role === 'viewer', 403, 'Viewers cannot record payments.');
        abort_unless((int) $settlement->trip_id === (int) $trip->id && (int) $transfer->settlement_id === (int) $settlement->id, 404);
        $data = $request->validate(['payment_reference' => 'nullable|string|max:160']);

        return DB::transaction(function () use ($request, $trip, $settlement, $transfer, $audit, $data) {
            $lockedTrip = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $actor = $lockedTrip->members()->where('user_id', $request->user()->id)->where('status', 'active')->lockForUpdate()->first();
            abort_unless($actor, 403);
            abort_if($actor->role === 'viewer', 403, 'Viewers cannot record payments.');
            if ($lockedTrip->visibility === TripVisibility::PRIVATE && (! $actor->last_private_verified_at || $actor->last_private_verified_at->lt(now()->subMinutes((int) config('splittrip.private_access_minutes', 30))))) {
                abort(423, 'Private-trip OTP required.');
            }
            $lockedSettlement = Settlement::whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            $lockedTransfer = SettlementTransfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($lockedTransfer->status === 'paid') {
                return response()->json(['transfer' => $lockedTransfer, 'settlement_status' => $lockedSettlement->status]);
            }
            if ($lockedSettlement->status !== 'confirmed') {
                throw ValidationException::withMessages(['settlement' => ['The settlement must be confirmed before payments can be recorded.']]);
            }
            if ((int) $lockedTransfer->from_user_id !== (int) $request->user()->id && ! in_array($actor->role, ['owner', 'admin'], true)) {
                abort(403, 'Only the payer or a trip administrator can record this payment.');
            }
            $lockedTransfer->update(['status' => 'paid', 'paid_at' => now(), 'payment_reference' => $data['payment_reference'] ?? null]);
            $audit->record($trip, $request->user(), 'settlement.transfer_paid', 'settlement_transfer', (int) $lockedTransfer->id, ['payment_reference' => $data['payment_reference'] ?? null, 'amount_minor' => $lockedTransfer->amount_minor]);
            if (! $lockedSettlement->transfers()->where('status', '!=', 'paid')->exists()) {
                $lockedSettlement->update(['status' => 'completed']);
            }

            return response()->json(['transfer' => $lockedTransfer->fresh(), 'settlement_status' => $lockedSettlement->fresh()->status]);
        });
    }

    private function unsettledExpenses(Trip $trip)
    {
        $alreadySettled = DB::table('settlement_expense_snapshots')
            ->join('settlements', 'settlements.id', '=', 'settlement_expense_snapshots.settlement_id')
            ->whereIn('settlements.status', ['confirmed', 'completed'])
            ->select('settlement_expense_snapshots.expense_id');

        return $trip->expenses()->whereNotIn('expenses.id', $alreadySettled);
    }

    private function ledger(Trip $trip): array
    {
        $expenses = $this->unsettledExpenses($trip);
        $paid = (clone $expenses)->selectRaw('payer_id, SUM(amount_minor) as total')->groupBy('payer_id')->pluck('total', 'payer_id')->map(fn ($v) => (int) $v)->all();
        $shares = (clone $expenses)->join('expense_splits', 'expenses.id', '=', 'expense_splits.expense_id')->selectRaw('expense_splits.user_id, SUM(expense_splits.share_minor) as total')->groupBy('expense_splits.user_id')->pluck('total', 'user_id')->map(fn ($v) => (int) $v)->all();

        return app(SettlementCalculator::class)->balances($paid, $shares);
    }

    private function authorizeMember(Request $request, Trip $trip)
    {
        $member = $trip->members()->where('user_id', $request->user()->id)->where('status', 'active')->first();
        abort_unless($member, 403);
        if ($trip->visibility === TripVisibility::PRIVATE && (! $member->last_private_verified_at || $member->last_private_verified_at->lt(now()->subMinutes((int) config('splittrip.private_access_minutes', 30))))) {
            abort(423, 'Private-trip OTP required.');
        }

        return $member;
    }

    private function authorizeManager(Request $request, Trip $trip): void
    {
        $member = $this->authorizeMember($request, $trip);
        abort_unless(in_array($member->role, ['owner', 'admin'], true), 403, 'Only trip administrators can manage settlements.');
    }
}
