<?php
namespace App\Http\Controllers\Api\V1;
use App\Enums\TripVisibility;
use App\Models\Settlement;
use App\Models\Trip;
use App\Services\AuditLogger;
use App\Services\SettlementCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class SettlementController
{
 public function preview(Request $r,Trip $trip,SettlementCalculator $calc){$this->authorizeMember($r,$trip);$ledger=$this->ledger($trip);return response()->json(['currency'=>$trip->currency,'balances_minor'=>$ledger,'transfers'=>$calc->transfers($ledger)]);}
 public function index(Request $r,Trip $trip){$this->authorizeMember($r,$trip);return $trip->newQuery()->findOrFail($trip->id)->settlements()->with('transfers')->latest()->paginate(20);}
 public function store(Request $r,Trip $trip,SettlementCalculator $calc,AuditLogger $audit){$this->authorizeMember($r,$trip);$ledger=$this->ledger($trip);$transfers=$calc->transfers($ledger);return DB::transaction(function()use($r,$trip,$transfers,$audit){$s=Settlement::create(['trip_id'=>$trip->id,'created_by'=>$r->user()->id,'currency'=>$trip->currency,'status'=>'proposed']);foreach($transfers as $t)$s->transfers()->create(['from_user_id'=>$t['from'],'to_user_id'=>$t['to'],'amount_minor'=>$t['amount_minor'],'status'=>'proposed']);$audit->record($trip,$r->user(),'settlement.proposed','settlement',$s->id,['transfer_count'=>count($transfers)]);return response()->json($s->load('transfers'),201);});}
 private function ledger(Trip $trip):array{$paid=$trip->expenses()->selectRaw('payer_id, SUM(amount_minor) as total')->groupBy('payer_id')->pluck('total','payer_id')->map(fn($v)=>(int)$v)->all();$shares=$trip->expenses()->join('expense_splits','expenses.id','=','expense_splits.expense_id')->selectRaw('expense_splits.user_id, SUM(expense_splits.share_minor) as total')->groupBy('expense_splits.user_id')->pluck('total','user_id')->map(fn($v)=>(int)$v)->all();return app(SettlementCalculator::class)->balances($paid,$shares);}
 private function authorizeMember(Request $r,Trip $trip):void{$m=$trip->members()->where('user_id',$r->user()->id)->where('status','active')->first();abort_unless($m,403);if($trip->visibility===TripVisibility::PRIVATE&&(!$m->last_private_verified_at||$m->last_private_verified_at->lt(now()->subMinutes(30))))abort(423,'Private-trip OTP required.');}
}
