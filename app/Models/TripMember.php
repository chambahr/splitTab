<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TripMember extends Model
{
    protected $fillable = ['trip_id', 'user_id', 'role', 'status', 'joined_at', 'last_private_verified_at'];

    protected function casts(): array
    {
        return ['joined_at' => 'immutable_datetime', 'last_private_verified_at' => 'immutable_datetime'];
    }

    public function trip()
    {
        return $this->belongsTo(Trip::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
