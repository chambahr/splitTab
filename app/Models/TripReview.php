<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TripReview extends Model
{
    protected $fillable = ['trip_id','reviewer_id','reviewee_id','rating','title','body'];
    public function trip() { return $this->belongsTo(Trip::class); }
    public function reviewer() { return $this->belongsTo(User::class,'reviewer_id'); }
    public function reviewee() { return $this->belongsTo(User::class,'reviewee_id'); }
}
