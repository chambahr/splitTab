<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    protected $fillable = ['country_code','country_name','region','name','slug','is_active'];
    protected function casts(): array { return ['is_active' => 'boolean']; }
    public function trips() { return $this->hasMany(Trip::class); }
}
