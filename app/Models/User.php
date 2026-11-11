<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
    protected $fillable = ['name','email','password','phone','username','bio','avatar_url','home_city','home_city_id'];
    protected $hidden = ['password','remember_token'];
    protected function casts(): array { return ['email_verified_at'=>'datetime','password'=>'hashed']; }
    public function tripMemberships(){ return $this->hasMany(TripMember::class); }
    public function trips(){ return $this->belongsToMany(Trip::class,'trip_members')->withPivot(['role','status','joined_at'])->withTimestamps(); }
    public function expensesPaid(){ return $this->hasMany(Expense::class,'payer_id'); }
    public function ownedTrips(){ return $this->hasMany(Trip::class,'owner_id'); }
    public function reviewsReceived(){ return $this->hasMany(TripReview::class,'reviewee_id'); }
    public function reviewsWritten(){ return $this->hasMany(TripReview::class,'reviewer_id'); }
    public function coinAccount(){ return $this->hasOne(CoinAccount::class); }
    public function homeCity(){ return $this->belongsTo(City::class, 'home_city_id'); }
}
