<?php
namespace Tests\Feature;
use App\Models\Trip;
use App\Models\TripMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class TripExpenseApiTest extends TestCase
{
 use RefreshDatabase;
 public function test_authenticated_user_can_create_trip_and_is_owner():void{$u=User::factory()->create();$r=$this->actingAs($u)->postJson('/api/v1/trips',['name'=>'Weekend away','visibility'=>'private','location_mode'=>'city','destination'=>'Bordeaux','timezone'=>'Europe/Paris','currency'=>'EUR','budget_minor'=>50000]);$r->assertCreated()->assertJsonPath('name','Weekend away');$this->assertDatabaseHas('trip_members',['trip_id'=>$r->json('id'),'user_id'=>$u->id,'role'=>'owner']);}
 public function test_public_trip_can_be_discovered_and_joined():void{$owner=User::factory()->create();$guest=User::factory()->create();$trip=Trip::create(['owner_id'=>$owner->id,'name'=>'Open city walk','visibility'=>'public','location_mode'=>'city','timezone'=>'UTC','currency'=>'EUR','budget_minor'=>0]);$this->actingAs($guest)->getJson('/api/v1/trips/discover')->assertOk()->assertJsonFragment(['name'=>'Open city walk']);$this->actingAs($guest)->postJson("/api/v1/trips/{$trip->id}/join")->assertCreated();$this->assertDatabaseHas('trip_members',['trip_id'=>$trip->id,'user_id'=>$guest->id]);}
 public function test_private_trip_is_not_discoverable_or_accessible_without_membership():void{$owner=User::factory()->create();$other=User::factory()->create();$trip=Trip::create(['owner_id'=>$owner->id,'name'=>'Private escape','visibility'=>'private','location_mode'=>'city','timezone'=>'UTC','currency'=>'EUR','budget_minor'=>0]);$this->actingAs($other)->getJson('/api/v1/trips/discover')->assertJsonMissing(['name'=>'Private escape']);$this->actingAs($other)->getJson("/api/v1/trips/{$trip->id}")->assertForbidden();}
 public function test_private_trip_requires_recent_otp_verification():void{$owner=User::factory()->create();$trip=Trip::create(['owner_id'=>$owner->id,'name'=>'Private escape','visibility'=>'private','location_mode'=>'city','timezone'=>'UTC','currency'=>'EUR','budget_minor'=>0]);TripMember::create(['trip_id'=>$trip->id,'user_id'=>$owner->id,'role'=>'owner','status'=>'active']);$this->actingAs($owner)->getJson("/api/v1/trips/{$trip->id}")->assertStatus(423)->assertJsonPath('code','PRIVATE_OTP_REQUIRED');}
}
