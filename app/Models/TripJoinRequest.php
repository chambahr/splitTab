<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TripJoinRequest extends Model
{
    protected $fillable = ['trip_id','user_id','status','message','reviewed_by','reviewed_at'];
    protected function casts(): array { return ['reviewed_at' => 'datetime']; }
    public function trip() { return $this->belongsTo(Trip::class); }
    public function user() { return $this->belongsTo(User::class); }
}
