<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\CoinAccount;
use App\Models\Trip;
use App\Models\TripMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SocialDiscoveryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_trip_and_city_discovery_are_available_without_authentication(): void
    {
        $city = City::query()->firstOrFail();
        $owner = User::factory()->create(['username' => 'host_one']);
        $trip = Trip::create(['owner_id'=>$owner->id,'name'=>'Community walk','description'=>'A public walk for local explorers.','visibility'=>'public','location_mode'=>'city','timezone'=>'Africa/Douala','currency'=>'XAF','budget_minor'=>50000,'trip_type'=>'event','city_id'=>$city->id]);
        $this->getJson('/api/v1/cities')->assertOk();
        $this->getJson('/api/v1/public/trips')->assertOk()->assertJsonFragment(['name'=>'Community walk']);
        $this->getJson('/api/v1/public/trips/'.$trip->id)->assertOk()->assertJsonPath('host.username','host_one');
    }

    public function test_private_trip_is_not_exposed_by_public_endpoints(): void
    {
        $owner = User::factory()->create();
        $trip = Trip::create(['owner_id'=>$owner->id,'name'=>'Private plan','visibility'=>'private','location_mode'=>'city','timezone'=>'UTC','currency'=>'XAF','budget_minor'=>0]);
        $this->getJson('/api/v1/public/trips/'.$trip->id)->assertNotFound();
    }

    public function test_public_trip_join_requires_host_approval(): void
    {
        $owner = User::factory()->create();
        $guest = User::factory()->create();
        $trip = Trip::create(['owner_id'=>$owner->id,'name'=>'Open plan','visibility'=>'public','location_mode'=>'city','timezone'=>'UTC','currency'=>'XAF','budget_minor'=>0]);
        TripMember::create(['trip_id'=>$trip->id,'user_id'=>$owner->id,'role'=>'owner','status'=>'active']);
        $join = $this->actingAs($guest)->postJson('/api/v1/trips/'.$trip->id.'/join')->assertCreated();
        $this->assertDatabaseMissing('trip_members',['trip_id'=>$trip->id,'user_id'=>$guest->id]);
        $this->actingAs($owner)->postJson('/api/v1/trips/'.$trip->id.'/join-requests/'.$join->json('id').'/approve')->assertOk();
        $this->assertDatabaseHas('trip_members',['trip_id'=>$trip->id,'user_id'=>$guest->id,'role'=>'member','status'=>'active']);
    }

    public function test_completed_trip_participants_can_review_each_other_only_once(): void
    {
        $owner = User::factory()->create();
        $participant = User::factory()->create();
        $trip = Trip::create(['owner_id'=>$owner->id,'name'=>'Finished weekend','visibility'=>'public','location_mode'=>'city','timezone'=>'UTC','currency'=>'XAF','budget_minor'=>0,'status'=>'completed']);
        TripMember::create(['trip_id'=>$trip->id,'user_id'=>$owner->id,'role'=>'owner','status'=>'active']);
        TripMember::create(['trip_id'=>$trip->id,'user_id'=>$participant->id,'role'=>'member','status'=>'active']);
        $payload = ['reviewee_id'=>$participant->id,'rating'=>5,'title'=>'Great host','body'=>'Everything was well organised.'];
        $this->actingAs($owner)->postJson('/api/v1/trips/'.$trip->id.'/reviews',$payload)->assertCreated();
        $this->actingAs($owner)->postJson('/api/v1/trips/'.$trip->id.'/reviews',$payload)->assertStatus(409);
        $this->assertDatabaseHas('trip_reviews',['trip_id'=>$trip->id,'reviewer_id'=>$owner->id,'reviewee_id'=>$participant->id,'rating'=>5]);
    }

    public function test_coin_gift_is_atomic_and_idempotent(): void
    {
        $sender = User::factory()->create();
        $host = User::factory()->create();
        $trip = Trip::create(['owner_id'=>$host->id,'name'=>'Public host plan','visibility'=>'public','location_mode'=>'city','timezone'=>'UTC','currency'=>'XAF','budget_minor'=>0]);
        CoinAccount::create(['user_id'=>$sender->id,'balance'=>80]);
        $payload = ['amount'=>10,'idempotency_key'=>'edb3f89b-82a0-4dd5-ae7e-31c7e0bd0b7e'];
        $this->actingAs($sender)->postJson('/api/v1/public/trips/'.$trip->id.'/gift-coins',$payload)->assertCreated();
        $this->actingAs($sender)->postJson('/api/v1/public/trips/'.$trip->id.'/gift-coins',$payload)->assertOk();
        $this->assertDatabaseHas('coin_accounts',['user_id'=>$sender->id,'balance'=>70]);
        $this->assertDatabaseHas('coin_accounts',['user_id'=>$host->id,'balance'=>10]);
    }
}
