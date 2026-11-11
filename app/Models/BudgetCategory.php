<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class BudgetCategory extends Model
{
 protected $fillable=['name','slug','is_system'];
 protected function casts():array{return ['is_system'=>'boolean'];}
 public function budgets(){return $this->hasMany(Budget::class,'category_id');}
}
