<?php
namespace App\Http\Controllers\Api\V1;

use App\Enums\TripVisibility;
use App\Models\Budget;
use App\Models\Trip;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BudgetController
{
    public function categories() { return response()->json(\App\Models\BudgetCategory::query()->orderBy('name')->get(['id', 'name', 'slug'])); }

    public function index(Request $request, Trip $trip)
    {
        $this->member($request, $trip);
        $budgets = $trip->budgets()->with('category')->orderBy('name')->get();
        $spent = $trip->expenses()->select('category', DB::raw('SUM(amount_minor) as total'))
            ->groupBy('category')->pluck('total', 'category');
        return response()->json($budgets->map(function (Budget $budget) use ($spent) {
            $slug = $budget->category?->slug;
            $actual = (int) ($slug ? ($spent[$slug] ?? 0) : 0);
            return [...$budget->toArray(), 'actual_minor' => $actual, 'remaining_minor' => (int) $budget->planned_minor - $actual];
        }));
    }

    public function store(Request $request, Trip $trip, AuditLogger $audit)
    {
        $member = $this->member($request, $trip);
        abort_if($member->role === 'viewer', 403, 'Viewers cannot manage budgets.');
        $data = $this->validated($request, $trip);
        return DB::transaction(function () use ($request, $trip, $data, $audit) {
            $lockedTrip = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $lockedMember = $this->member($request, $lockedTrip);
            abort_if($lockedMember->role === 'viewer', 403, 'Viewers cannot manage budgets.');
            $budget = $lockedTrip->budgets()->create($data);
            $audit->record($lockedTrip, $request->user(), 'budget.created', 'budget', (int) $budget->id, ['planned_minor' => $budget->planned_minor]);
            return response()->json($budget->load('category'), 201);
        });
    }

    public function update(Request $request, Trip $trip, Budget $budget, AuditLogger $audit)
    {
        $member = $this->member($request, $trip);
        abort_if($member->role === 'viewer', 403, 'Viewers cannot manage budgets.');
        abort_unless((int) $budget->trip_id === (int) $trip->id, 404);
        $data = $this->validated($request, $trip, true);
        return DB::transaction(function () use ($request, $trip, $budget, $data, $audit) {
            $lockedTrip = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $lockedMember = $this->member($request, $lockedTrip);
            abort_if($lockedMember->role === 'viewer', 403, 'Viewers cannot manage budgets.');
            $lockedBudget = Budget::whereKey($budget->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $lockedBudget->trip_id === (int) $lockedTrip->id, 404);
            $lockedBudget->update($data);
            $audit->record($lockedTrip, $request->user(), 'budget.updated', 'budget', (int) $lockedBudget->id, ['changed_fields' => array_keys($data)]);
            return response()->json($lockedBudget->fresh()->load('category'));
        });
    }

    public function destroy(Request $request, Trip $trip, Budget $budget, AuditLogger $audit)
    {
        $member = $this->member($request, $trip);
        abort_if($member->role === 'viewer', 403, 'Viewers cannot manage budgets.');
        abort_unless((int) $budget->trip_id === (int) $trip->id, 404);
        return DB::transaction(function () use ($request, $trip, $budget, $audit) {
            $lockedTrip = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $lockedMember = $this->member($request, $lockedTrip);
            abort_if($lockedMember->role === 'viewer', 403, 'Viewers cannot manage budgets.');
            $lockedBudget = Budget::whereKey($budget->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $lockedBudget->trip_id === (int) $lockedTrip->id, 404);
            $id = (int) $lockedBudget->id;
            $lockedBudget->delete();
            $audit->record($lockedTrip, $request->user(), 'budget.deleted', 'budget', $id);
            return response()->noContent();
        });
    }

    private function validated(Request $request, Trip $trip, bool $partial = false): array
    {
        $data = $request->validate([
            'category_id' => [$partial ? 'sometimes' : 'nullable', 'nullable', 'integer', 'exists:budget_categories,id'],
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:120'],
            'planned_minor' => [$partial ? 'sometimes' : 'required', 'integer', 'min:0', 'max:9000000000000000'],
            'currency' => [$partial ? 'sometimes' : 'required', 'string', 'size:3'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
        ]);
        if (isset($data['currency']) && strtoupper($data['currency']) !== strtoupper($trip->currency)) {
            throw ValidationException::withMessages(['currency' => ['Budget currency must match the trip currency.']]);
        }
        if (isset($data['currency'])) $data['currency'] = strtoupper($data['currency']);
        if (array_key_exists('category_id', $data) && $data['category_id'] !== null && !\App\Models\BudgetCategory::whereKey($data['category_id'])->exists()) {
            throw ValidationException::withMessages(['category_id' => ['Unknown budget category.']]);
        }
        return $data;
    }

    private function member(Request $request, Trip $trip)
    {
        $member = $trip->members()->where('user_id', $request->user()->id)->where('status', 'active')->first();
        abort_unless($member, 403);
        if ($trip->visibility === TripVisibility::PRIVATE && (!$member->last_private_verified_at || $member->last_private_verified_at->lt(now()->subMinutes((int) config('splittrip.private_access_minutes', 30))))) abort(423, 'Private-trip OTP required.');
        return $member;
    }
}
