<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Trip;
use App\Models\TripJoinRequest;
use App\Models\TripMember;
use App\Services\AuditLogger;
use App\Services\InAppNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TripJoinRequestController
{
    public function store(Request $request, Trip $trip, AuditLogger $audit, InAppNotifier $notifier)
    {
        abort_unless($trip->visibility === 'public' && $trip->status !== 'completed', 404);
        $data = $request->validate(['message' => 'nullable|string|max:1000']);
        return DB::transaction(function () use ($request, $trip, $data, $audit, $notifier) {
            $locked = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $existingMember = $locked->members()->where('user_id', $request->user()->id)->where('status', 'active')->exists();
            if ($existingMember) return response()->json(['message' => 'You are already a member.'], 409);
            $join = TripJoinRequest::where('trip_id', $locked->id)->where('user_id', $request->user()->id)->lockForUpdate()->first();
            if ($join && $join->status === 'pending') return response()->json($join, 200);
            if (!$join) $join = new TripJoinRequest(['trip_id' => $locked->id, 'user_id' => $request->user()->id]);
            $join->fill(['status' => 'pending', 'message' => $data['message'] ?? null, 'reviewed_by' => null, 'reviewed_at' => null])->save();
            $audit->record($locked, $request->user(), 'trip.join_requested', 'trip_join_request', (int) $join->id);
            $notifier->send($locked->owner, 'trip.join_requested', ['trip_id'=>$locked->id,'trip_name'=>$locked->name,'request_id'=>$join->id,'requester'=>$request->user()->name]);
            return response()->json($join, 201);
        });
    }

    public function index(Request $request, Trip $trip)
    {
        $this->assertHostOrAdmin($request, $trip);
        return response()->json($trip->joinRequests()->with('user:id,name,username,avatar_url')->latest()->paginate(30));
    }

    public function approve(Request $request, Trip $trip, TripJoinRequest $joinRequest, AuditLogger $audit, InAppNotifier $notifier)
    {
        $this->assertHostOrAdmin($request, $trip);
        return DB::transaction(function () use ($request, $trip, $joinRequest, $audit, $notifier) {
            $lockedTrip = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedTrip->visibility === 'public' && $lockedTrip->status !== 'completed', 409, 'This trip is no longer accepting join requests.');
            $join = TripJoinRequest::whereKey($joinRequest->id)->where('trip_id', $lockedTrip->id)->lockForUpdate()->firstOrFail();
            abort_unless($join->status === 'pending', 409, 'This request is no longer pending.');
            if ($lockedTrip->participant_limit && $lockedTrip->members()->where('status', 'active')->count() >= $lockedTrip->participant_limit) abort(409, 'This trip is full.');
            $member = TripMember::firstOrNew(['trip_id' => $lockedTrip->id, 'user_id' => $join->user_id]);
            $member->fill(['role' => 'member', 'status' => 'active', 'joined_at' => now(), 'last_private_verified_at' => null])->save();
            $join->update(['status' => 'approved', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
            $audit->record($lockedTrip, $request->user(), 'trip.join_approved', 'trip_join_request', (int) $join->id);
            $notifier->send($join->user, 'trip.join_approved', ['trip_id'=>$lockedTrip->id,'trip_name'=>$lockedTrip->name]);
            return response()->json(['request' => $join->fresh(), 'membership' => $member]);
        });
    }

    public function decline(Request $request, Trip $trip, TripJoinRequest $joinRequest, AuditLogger $audit, InAppNotifier $notifier)
    {
        $this->assertHostOrAdmin($request, $trip);
        return DB::transaction(function () use ($request, $trip, $joinRequest, $audit, $notifier) {
            $join = TripJoinRequest::whereKey($joinRequest->id)->where('trip_id', $trip->id)->lockForUpdate()->firstOrFail();
            abort_unless($join->status === 'pending', 409, 'This request is no longer pending.');
            $join->update(['status' => 'declined', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
            $audit->record($trip, $request->user(), 'trip.join_declined', 'trip_join_request', (int) $join->id);
            $notifier->send($join->user, 'trip.join_declined', ['trip_id'=>$trip->id,'trip_name'=>$trip->name]);
            return response()->json($join);
        });
    }

    public function cancel(Request $request, Trip $trip, TripJoinRequest $joinRequest)
    {
        $join = TripJoinRequest::whereKey($joinRequest->id)->where('trip_id', $trip->id)->where('user_id', $request->user()->id)->firstOrFail();
        abort_unless($join->status === 'pending', 409, 'Only pending requests can be cancelled.');
        $join->update(['status' => 'cancelled']);
        return response()->noContent();
    }

    private function assertHostOrAdmin(Request $request, Trip $trip): void
    {
        $member = $trip->members()->where('user_id', $request->user()->id)->where('status', 'active')->first();
        abort_unless($member && in_array($member->role, ['owner', 'admin'], true), 403);
    }
}
