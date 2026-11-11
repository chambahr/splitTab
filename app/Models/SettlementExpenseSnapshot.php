<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class SettlementExpenseSnapshot extends Model
{
    protected $fillable = ['settlement_id', 'expense_id', 'snapshot'];

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }

    public function settlement()
    {
        return $this->belongsTo(Settlement::class);
    }

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }
}
