<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Settlement extends Model
{
    protected $fillable=['trip_id','created_by','currency','status','confirmed_at'];
    protected function casts():array{return ['confirmed_at'=>'immutable_datetime'];}
    public function transfers(){return $this->hasMany(SettlementTransfer::class);}
 public function expenseSnapshots(){return $this->hasMany(SettlementExpenseSnapshot::class);}
}
