<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {public function up():void{
 Schema::create('budget_categories',function(Blueprint $t){$t->id();$t->string('name',80);$t->string('slug',80)->unique();$t->boolean('is_system')->default(true);$t->timestamps();});
 Schema::create('budgets',function(Blueprint $t){$t->id();$t->foreignId('trip_id')->constrained()->cascadeOnDelete();$t->foreignId('category_id')->nullable()->constrained('budget_categories')->nullOnDelete();$t->string('name',120);$t->unsignedBigInteger('planned_minor');$t->char('currency',3);$t->date('period_start')->nullable();$t->date('period_end')->nullable();$t->timestamps();$t->index(['trip_id','category_id']);});
}public function down():void{Schema::dropIfExists('budgets');Schema::dropIfExists('budget_categories');}};
