<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_expense_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_id')->constrained()->restrictOnDelete();
            $table->jsonb('snapshot');
            $table->timestamps();
            $table->unique(['settlement_id', 'expense_id']);
            $table->index('expense_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_expense_snapshots');
    }
};
