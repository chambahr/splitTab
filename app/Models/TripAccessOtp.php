<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TripAccessOtp extends Model
{
    protected $fillable = ['trip_id', 'user_id', 'code_hash', 'expires_at', 'attempts', 'consumed_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime', 'attempts' => 'integer'];
    }
}
