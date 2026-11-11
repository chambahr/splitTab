<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseSplit extends Model
{
    protected $fillable = ['expense_id', 'user_id', 'share_minor', 'percentage_basis_points', 'shares', 'comment', 'settled_minor'];

    protected function casts(): array
    {
        return ['share_minor' => 'integer', 'percentage_basis_points' => 'integer', 'shares' => 'integer', 'settled_minor' => 'integer'];
    }

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
