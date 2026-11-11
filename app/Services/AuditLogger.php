<?php
namespace App\Services;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Trip;
class AuditLogger
{
 public function record(?Trip $trip,?User $user,string $action,?string $type=null,?int $id=null,array $metadata=[]):void
 { AuditLog::create(['trip_id'=>$trip?->id,'user_id'=>$user?->id,'action'=>$action,'subject_type'=>$type,'subject_id'=>$id,'metadata'=>$metadata,'created_at'=>now()]); }
}
