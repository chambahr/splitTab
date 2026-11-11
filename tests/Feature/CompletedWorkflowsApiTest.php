<?php

namespace Tests\Feature;

use App\Models\BudgetCategory;
use App\Models\Expense;
use App\Models\Trip;
use App\Models\TripMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CompletedWorkflowsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_trip_member_can_create_and_read_budget_with_actuals(): void
    {
        [$owner, $trip] = $this->publicTrip();
        $category = BudgetCategory::where('slug', 'food')->firstOrFail();
        $response = $this->actingAs($owner)->postJson("/api/v1/trips/{$trip->id}/budgets", [
            'category_id' => $category->id, 'name' => 'Food budget', 'planned_minor' => 25000, 'currency' => 'EUR',
        ]);
        $response->assertCreated()->assertJsonPath('planned_minor', 25000);
        $this->actingAs($owner)->getJson("/api/v1/trips/{$trip->id}/budgets")
            ->assertOk()->assertJsonFragment(['name' => 'Food budget', 'actual_minor' => 0, 'remaining_minor' => 25000]);
    }

    public function test_viewer_cannot_add_expense_comments(): void
    {
        [$owner, $trip] = $this->publicTrip();
        $viewer = User::factory()->create();
        TripMember::create(['trip_id' => $trip->id, 'user_id' => $viewer->id, 'role' => 'viewer', 'status' => 'active']);
        $expense = Expense::create(['trip_id' => $trip->id, 'payer_id' => $owner->id, 'created_by' => $owner->id, 'description' => 'Train', 'category' => 'transport', 'amount_minor' => 1000, 'currency' => 'EUR', 'allocation_method' => 'equal', 'spent_at' => now()]);
        $this->actingAs($viewer)->postJson("/api/v1/trips/{$trip->id}/expenses/{$expense->id}/comments", ['body' => 'Looks good.'])->assertForbidden();
    }

    public function test_expense_idempotency_key_cannot_be_reused_with_different_payload(): void
    {
        [$owner, $trip] = $this->publicTrip();
        $payload = $this->expensePayload($owner);
        $payload['idempotency_key'] = '4a8c1b8e-95b4-4b80-9b95-12f28d93e011';
        $this->actingAs($owner)->postJson("/api/v1/trips/{$trip->id}/expenses", $payload)->assertCreated();
        $payload['amount_minor'] = 1200;
        $this->actingAs($owner)->postJson("/api/v1/trips/{$trip->id}/expenses", $payload)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    }

    public function test_expense_can_be_updated_before_settlement_and_deleted_without_losing_audit(): void
    {
        [$owner, $trip] = $this->publicTrip();
        $payload = $this->expensePayload($owner);
        $created = $this->actingAs($owner)->postJson("/api/v1/trips/{$trip->id}/expenses", $payload)->assertCreated();
        $expenseId = $created->json('id');
        $updated = $this->actingAs($owner)->patchJson("/api/v1/trips/{$trip->id}/expenses/{$expenseId}", [...$payload, 'amount_minor' => 1500]);
        $updated->assertOk()->assertJsonPath('amount_minor', 1500);
        $this->actingAs($owner)->deleteJson("/api/v1/trips/{$trip->id}/expenses/{$expenseId}")->assertNoContent();
        $this->assertDatabaseHas('audit_logs', ['action' => 'expense.deleted', 'subject_id' => $expenseId]);
    }

    public function test_settlement_requires_confirmation_and_records_payment_once(): void
    {
        [$owner, $trip] = $this->publicTrip();
        $debtor = User::factory()->create();
        TripMember::create(['trip_id' => $trip->id, 'user_id' => $debtor->id, 'role' => 'member', 'status' => 'active']);
        $payload = $this->expensePayload($owner, $debtor);
        $this->actingAs($owner)->postJson("/api/v1/trips/{$trip->id}/expenses", $payload)->assertCreated();
        $proposal = $this->actingAs($debtor)->postJson("/api/v1/trips/{$trip->id}/settlements")->assertCreated();
        $settlementId = $proposal->json('id');
        $transferId = $proposal->json('transfers.0.id');
        $this->actingAs($debtor)->postJson("/api/v1/trips/{$trip->id}/settlements/{$settlementId}/transfers/{$transferId}/paid")->assertUnprocessable();
        $this->actingAs($owner)->postJson("/api/v1/trips/{$trip->id}/settlements/{$settlementId}/confirm")->assertOk();
        $paid = $this->actingAs($debtor)->postJson("/api/v1/trips/{$trip->id}/settlements/{$settlementId}/transfers/{$transferId}/paid", ['payment_reference' => 'bank-ref-123']);
        $paid->assertOk()->assertJsonPath('settlement_status', 'completed');
        $this->actingAs($debtor)->postJson("/api/v1/trips/{$trip->id}/settlements/{$settlementId}/transfers/{$transferId}/paid")->assertOk();
    }

    private function publicTrip(): array
    {
        $owner = User::factory()->create();
        $trip = Trip::create(['owner_id' => $owner->id, 'name' => 'Test trip', 'visibility' => 'public', 'location_mode' => 'city', 'timezone' => 'UTC', 'currency' => 'EUR', 'budget_minor' => 10000]);
        TripMember::create(['trip_id' => $trip->id, 'user_id' => $owner->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now()]);

        return [$owner, $trip];
    }

    private function expensePayload(User $owner, ?User $participant = null): array
    {
        $participant ??= $owner;

        return ['payer_id' => $owner->id, 'description' => 'Train tickets', 'category' => 'transport', 'amount_minor' => 1000, 'currency' => 'EUR', 'allocation_method' => 'equal', 'spent_at' => now()->toISOString(), 'participants' => [['user_id' => $participant->id]]];
    }
}
