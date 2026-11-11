<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TripMembershipApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_list_active_trip_members(): void
    {
        [$owner, $trip] = $this->privateTripWithOwner();

        $member = User::factory()->create();

        TripMember::create([
            'trip_id' => $trip->id,
            'user_id' => $member->id,
            'role' => 'member',
            'status' => 'active',
        ]);

        $this->actingAs($owner)
            ->getJson("/api/v1/trips/{$trip->id}/members")
            ->assertOk()
            ->assertJsonFragment([
                'name' => $member->name,
            ]);
    }

    public function test_non_owner_cannot_change_member_roles(): void
    {
        [$owner, $trip] = $this->privateTripWithOwner();

        $member = User::factory()->create();

        $target = TripMember::create([
            'trip_id' => $trip->id,
            'user_id' => $member->id,
            'role' => 'member',
            'status' => 'active',
        ]);

        $this->actingAs($member)
            ->patchJson(
                "/api/v1/trips/{$trip->id}/members/{$target->id}/role",
                ['role' => 'admin']
            )
            ->assertForbidden();

        $this->assertDatabaseHas('trip_members', [
            'id' => $target->id,
            'role' => 'member',
        ]);
    }

    public function test_owner_can_promote_member_and_action_is_audited(): void
    {
        [$owner, $trip] = $this->privateTripWithOwner();

        $member = User::factory()->create();

        $target = TripMember::create([
            'trip_id' => $trip->id,
            'user_id' => $member->id,
            'role' => 'member',
            'status' => 'active',
        ]);

        $this->actingAs($owner)
            ->patchJson(
                "/api/v1/trips/{$trip->id}/members/{$target->id}/role",
                ['role' => 'admin']
            )
            ->assertOk()
            ->assertJsonPath('role', 'admin');

        $this->assertDatabaseHas('audit_logs', [
            'trip_id' => $trip->id,
            'action' => 'trip.member_role_updated',
        ]);
    }

    public function test_admin_can_remove_member_but_cannot_remove_another_admin(): void
    {
        [$owner, $trip] = $this->privateTripWithOwner();

        $admin = User::factory()->create();

        $adminMembership = TripMember::create([
            'trip_id' => $trip->id,
            'user_id' => $admin->id,
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->markPrivateAccessVerified($adminMembership);

        $member = User::factory()->create();

        $memberMembership = TripMember::create([
            'trip_id' => $trip->id,
            'user_id' => $member->id,
            'role' => 'member',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->deleteJson(
                "/api/v1/trips/{$trip->id}/members/{$memberMembership->id}"
            )
            ->assertNoContent();

        $this->assertDatabaseHas('trip_members', [
            'id' => $memberMembership->id,
            'status' => 'removed',
        ]);

        $anotherAdmin = User::factory()->create();

        $anotherAdminMembership = TripMember::create([
            'trip_id' => $trip->id,
            'user_id' => $anotherAdmin->id,
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->deleteJson(
                "/api/v1/trips/{$trip->id}/members/{$anotherAdminMembership->id}"
            )
            ->assertForbidden();
    }

    public function test_owner_can_transfer_ownership_to_an_active_member_atomically(): void
    {
        [$owner, $trip] = $this->privateTripWithOwner();

        $nextOwner = User::factory()->create();

        TripMember::create([
            'trip_id' => $trip->id,
            'user_id' => $nextOwner->id,
            'role' => 'member',
            'status' => 'active',
        ]);

        $this->actingAs($owner)
            ->postJson(
                "/api/v1/trips/{$trip->id}/transfer-ownership",
                ['user_id' => $nextOwner->id]
            )
            ->assertOk()
            ->assertJsonPath('trip.owner_id', $nextOwner->id)
            ->assertJsonPath('previous_owner.role', 'admin')
            ->assertJsonPath('new_owner.role', 'owner');

        $this->assertDatabaseHas('trips', [
            'id' => $trip->id,
            'owner_id' => $nextOwner->id,
        ]);

        $this->assertDatabaseHas('trip_members', [
            'trip_id' => $trip->id,
            'user_id' => $owner->id,
            'role' => 'admin',
        ]);

        $this->assertDatabaseHas('trip_members', [
            'trip_id' => $trip->id,
            'user_id' => $nextOwner->id,
            'role' => 'owner',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'trip_id' => $trip->id,
            'action' => 'trip.ownership_transferred',
        ]);
    }

    public function test_rejoining_public_trip_resets_previous_privileged_role(): void
    {
        $owner = User::factory()->create();
        $formerAdmin = User::factory()->create();

        $trip = Trip::create([
            'owner_id' => $owner->id,
            'name' => 'Public test trip',
            'visibility' => 'public',
            'location_mode' => 'city',
            'timezone' => 'UTC',
            'currency' => 'EUR',
            'budget_minor' => 0,
        ]);

        TripMember::create([
            'trip_id' => $trip->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $membership = TripMember::create([
            'trip_id' => $trip->id,
            'user_id' => $formerAdmin->id,
            'role' => 'admin',
            'status' => 'removed',
        ]);

        $join = $this->actingAs($formerAdmin)
            ->postJson("/api/v1/trips/{$trip->id}/join")
            ->assertCreated();

        $this->actingAs($owner)
            ->postJson("/api/v1/trips/{$trip->id}/join-requests/{$join->json('id')}/approve")
            ->assertOk();

        $this->assertDatabaseHas('trip_members', [
            'id' => $membership->id,
            'role' => 'member',
            'status' => 'active',
        ]);
    }

    public function test_owner_cannot_be_removed_or_demoted(): void
    {
        [$owner, $trip, $ownerMembership] = $this->privateTripWithOwner();

        $this->actingAs($owner)
            ->deleteJson(
                "/api/v1/trips/{$trip->id}/members/{$ownerMembership->id}"
            )
            ->assertStatus(409);

        $this->actingAs($owner)
            ->patchJson(
                "/api/v1/trips/{$trip->id}/members/{$ownerMembership->id}/role",
                ['role' => 'member']
            )
            ->assertStatus(409);

        $this->assertDatabaseHas('trip_members', [
            'id' => $ownerMembership->id,
            'role' => 'owner',
            'status' => 'active',
        ]);
    }

    /**
     * Create a private trip and an owner whose private access
     * verification timestamp is generated by PostgreSQL.
     */
    private function privateTripWithOwner(): array
    {
        $owner = User::factory()->create();

        $trip = Trip::create([
            'owner_id' => $owner->id,
            'name' => 'Private test trip',
            'visibility' => 'private',
            'location_mode' => 'city',
            'timezone' => 'UTC',
            'currency' => 'EUR',
            'budget_minor' => 10000,
        ]);

        $membership = TripMember::create([
            'trip_id' => $trip->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'status' => 'active',
        ]);

        $this->markPrivateAccessVerified($membership);

        return [$owner, $trip, $membership->fresh()];
    }

    /**
     * Set the timestamp using the database clock to avoid
     * PHP/PostgreSQL timestamp conversion differences in fixtures.
     *
     * This is test setup only. It does not bypass the OTP check.
     */
    private function markPrivateAccessVerified(TripMember $membership): void
    {
        DB::table('trip_members')
            ->where('id', $membership->id)
            ->update([
                'last_private_verified_at' => DB::raw('CURRENT_TIMESTAMP'),
            ]);

        $membership->refresh();

        $this->assertNotNull(
            $membership->last_private_verified_at,
            'Private verification timestamp was not persisted.'
        );

        $this->assertTrue(
            $membership->last_private_verified_at->gte(
                now()->subMinutes(
                    (int) config('splittrip.private_access_minutes', 30)
                )
            ),
            'Database-generated private verification timestamp is outside the allowed window. Check application and database clocks.'
        );
    }
}
