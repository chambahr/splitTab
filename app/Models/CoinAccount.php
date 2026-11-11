<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoinAccount extends Model
{
    protected $fillable = ['user_id','balance'];
    protected function casts(): array { return ['balance'=>'integer']; }
    public function user() { return $this->belongsTo(User::class); }
}
