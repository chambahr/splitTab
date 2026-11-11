<?php
namespace App\Models;
use App\Enums\AllocationMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Expense extends Model
{
    use SoftDeletes;
    protected $hidden=['idempotency_key','idempotency_fingerprint'];
    protected $fillable=['trip_id','payer_id','created_by','description','category','amount_minor','currency','allocation_method','spent_at','idempotency_key','idempotency_fingerprint'];
    protected function casts():array{return ['amount_minor'=>'integer','allocation_method'=>AllocationMethod::class,'spent_at'=>'immutable_datetime'];}
    public function trip(){return $this->belongsTo(Trip::class);}
    public function payer(){return $this->belongsTo(User::class,'payer_id');}
    public function splits(){return $this->hasMany(ExpenseSplit::class);}
    public function comments(){return $this->hasMany(ExpenseComment::class);}
}
