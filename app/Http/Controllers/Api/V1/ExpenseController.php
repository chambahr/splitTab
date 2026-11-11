<?php
namespace App\Http\Controllers\Api\V1;
use App\Enums\TripVisibility;
use App\Models\Expense;
use App\Models\ExpenseSplit;
use App\Models\Trip;
use App\Services\AuditLogger;
use App\Services\SettlementCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
class ExpenseController
{
 public function index(Request $r,Trip $trip){$this->authorizeMember($r,$trip);return $trip->expenses()->with(['payer:id,name','splits.user:id,name','comments.user:id,name'])->latest('spent_at')->paginate(30);}
 public function store(Request $r,Trip $trip,SettlementCalculator $calc,AuditLogger $audit){$this->authorizeMember($r,$trip);$d=$r->validate(['payer_id'=>'required|integer','description'=>'required|string|max:180','category'=>'required|in:accommodation,transport,food,activities,shopping,emergency,other','amount_minor'=>'required|integer|min:1','currency'=>'required|string|size:3','allocation_method'=>['required',Rule::in(['equal','manual'])],'spent_at'=>'required|date','participants'=>'required|array|min:1','participants.*.user_id'=>'required|integer|distinct','participants.*.share_minor'=>'required_if:allocation_method,manual|integer|min:0','participants.*.comment'=>'nullable|string|max:1000','comment'=>'nullable|string|max:2000','idempotency_key'=>'nullable|uuid']);
  if($d['currency']!==$trip->currency)throw ValidationException::withMessages(['currency'=>['Expense currency must match the trip currency in this release.']]);
  $memberIds=$trip->members()->where('status','active')->pluck('user_id')->map(fn($v)=>(int)$v)->all();if(!in_array((int)$d['payer_id'],$memberIds,true))throw ValidationException::withMessages(['payer_id'=>['Payer must be an active trip member.']]);
  $shares=[];foreach($d['participants'] as $p){if(!in_array((int)$p['user_id'],$memberIds,true))throw ValidationException::withMessages(['participants'=>['Every participant must be an active trip member.']]);$shares[(int)$p['user_id']]=$d['allocation_method']==='equal'?0:(int)$p['share_minor'];}
  if($d['allocation_method']==='equal')$shares=$calc->equalShares((int)$d['amount_minor'],array_keys($shares));else $calc->validateShares((int)$d['amount_minor'],$shares);
  return DB::transaction(function()use($r,$trip,$d,$shares,$audit){if(!empty($d['idempotency_key'])&&$existing=$trip->expenses()->where('idempotency_key',$d['idempotency_key'])->first())return response()->json($existing->load('splits'),200);$expense=Expense::create(['trip_id'=>$trip->id,'payer_id'=>$d['payer_id'],'created_by'=>$r->user()->id,'description'=>$d['description'],'category'=>$d['category'],'amount_minor'=>$d['amount_minor'],'currency'=>strtoupper($d['currency']),'allocation_method'=>$d['allocation_method'],'spent_at'=>$d['spent_at'],'idempotency_key'=>$d['idempotency_key']??null]);foreach($d['participants'] as $p){ExpenseSplit::create(['expense_id'=>$expense->id,'user_id'=>$p['user_id'],'share_minor'=>$shares[(int)$p['user_id']],'comment'=>$p['comment']??null]);}if(!empty($d['comment']))$expense->comments()->create(['user_id'=>$r->user()->id,'body'=>$d['comment']]);$audit->record($trip,$r->user(),'expense.created','expense',$expense->id,['amount_minor'=>$expense->amount_minor,'currency'=>$expense->currency]);return response()->json($expense->load(['splits.user:id,name','payer:id,name']),201);});
 }
 private function authorizeMember(Request $r,Trip $trip):void{$m=$trip->members()->where('user_id',$r->user()->id)->where('status','active')->first();abort_unless($m,403);if($trip->visibility===TripVisibility::PRIVATE&&(!$m->last_private_verified_at||$m->last_private_verified_at->lt(now()->subMinutes(30))))abort(423,'Private-trip OTP required.');}
}
