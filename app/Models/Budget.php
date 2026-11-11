<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Budget extends Model
{
    protected $fillable = ['trip_id', 'category_id', 'name', 'planned_minor', 'currency', 'period_start', 'period_end'];

    protected function casts(): array
    {
        return ['planned_minor' => 'integer', 'period_start' => 'immutable_date', 'period_end' => 'immutable_date'];
    }

    public function trip()
    {
        return $this->belongsTo(Trip::class);
    }

    public function category()
    {
        return $this->belongsTo(BudgetCategory::class, 'category_id');
    }
}
