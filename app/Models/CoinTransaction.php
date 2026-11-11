<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoinTransaction extends Model
{
    protected $fillable = ['sender_id','recipient_id','amount','type','note','idempotency_key'];
    protected function casts(): array { return ['amount'=>'integer']; }
    public function sender() { return $this->belongsTo(User::class,'sender_id'); }
    public function recipient() { return $this->belongsTo(User::class,'recipient_id'); }
}
