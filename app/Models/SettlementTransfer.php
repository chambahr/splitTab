<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SettlementTransfer extends Model
{
    protected $fillable=['settlement_id','from_user_id','to_user_id','amount_minor','status','paid_at','payment_reference'];
    protected function casts():array{return ['amount_minor'=>'integer','paid_at'=>'immutable_datetime'];}
    public function settlement(){return $this->belongsTo(Settlement::class);}
 public function fromUser(){return $this->belongsTo(User::class,'from_user_id');}
 public function toUser(){return $this->belongsTo(User::class,'to_user_id');}
}
