<?php
namespace App\Models;
use App\Enums\TripVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Trip extends Model
{
    use SoftDeletes;
    protected $fillable=['owner_id','name','description','visibility','location_mode','destination','start_at','end_at','timezone','currency','budget_minor','participant_limit','status'];
    protected function casts(): array { return ['visibility'=>TripVisibility::class,'start_at'=>'immutable_datetime','end_at'=>'immutable_datetime','budget_minor'=>'integer','participant_limit'=>'integer']; }
    public function owner(){return $this->belongsTo(User::class,'owner_id');}
    public function members(){return $this->hasMany(TripMember::class);}
    public function users(){return $this->belongsToMany(User::class,'trip_members')->withPivot(['role','status','joined_at'])->withTimestamps();}
    public function expenses(){return $this->hasMany(Expense::class);}
    public function budgets(){return $this->hasMany(Budget::class);}
    public function invitations(){return $this->hasMany(TripInvitation::class);}
    public function settlements(){return $this->hasMany(Settlement::class);}
    public function auditLogs(){return $this->hasMany(AuditLog::class);}
}
