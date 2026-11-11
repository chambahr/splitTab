<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TripInvitation extends Model
{
    protected $fillable = ['trip_id', 'invited_by', 'email', 'token_hash', 'expires_at', 'accepted_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    public function trip()
    {
        return $this->belongsTo(Trip::class);
    }
}
