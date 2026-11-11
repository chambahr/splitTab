<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseComment extends Model
{
    protected $fillable = ['expense_id', 'user_id', 'body'];

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
