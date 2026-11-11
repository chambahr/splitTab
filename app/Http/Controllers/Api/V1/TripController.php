<?php
namespace App\Http\Controllers\Api\V1;

use App\Enums\TripVisibility;
use App\Models\Trip;
use App\Models\TripMember;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class TripController
{
    public function index(Request $request)
    {
        return Trip::query()
            ->whereHas('members', fn ($q) => $q->where('user_id', $request->user()->id)->where('status', 'active'))
            ->where(fn ($q) => $q->where('visibility', 'public')->orWhereHas('members', fn ($m) => $m->where('user_id', $request->user()->id)->where('status', 'active')->where('last_private_verified_at', '>=', now()->subMinutes((int) config('splittrip.private_access_minutes', 30)))))
            ->withCount(['members' => fn ($q) => $q->where('status', 'active')])->latest()->paginate(20);
    }

    public function discover()
    {
        return Trip::query()->where('visibility', 'public')->where('status', 'planning')
            ->withCount(['members' => fn ($q) => $q->where('status', 'active')])->latest()->paginate(20);
    }

    public function store(Request $request, AuditLogger $audit)
    {
        $data = $request->validate([
            'name' => 'required|string|max:140', 'description' => 'required|string|min:20|max:5000',
            'trip_type' => 'required|in:event,leisure,fun_hangout', 'city_id' => 'required|integer|exists:cities,id',
            'photo' => 'nullable|image|max:8192',
            'visibility' => ['required', Rule::enum(TripVisibility::class)], 'location_mode' => 'required|in:city,route,venue',
            'destination' => 'nullable|string|max:180', 'start_at' => 'nullable|date', 'end_at' => 'nullable|date|after_or_equal:start_at',
            'timezone' => 'required|timezone', 'currency' => 'required|string|size:3', 'budget_minor' => 'required|integer|min:0|max:9000000000000000',
            'participant_limit' => 'nullable|integer|min:2|max:500',
        ]);
        return DB::transaction(function () use ($request, $data, $audit) {
            if ($request->hasFile('photo')) $data['photo_path'] = $request->file('photo')->store('trips', 'public');
            unset($data['photo']);
            $trip = Trip::create([...$data, 'owner_id' => $request->user()->id, 'currency' => strtoupper($data['currency'])]);
            TripMember::create(['trip_id' => $trip->id, 'user_id' => $request->user()->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now()]);
            $audit->record($trip, $request->user(), 'trip.created', 'trip', (int) $trip->id, ['visibility' => $trip->visibility->value]);
            return response()->json($trip->load('members.user:id,name'), 201);
        });
    }

    public function show(Request $request, Trip $trip)
    {
        $member = $trip->members()->where('user_id', $request->user()->id)->where('status', 'active')->first();
        if ($trip->visibility === TripVisibility::PRIVATE && !$member) return response()->json(['message' => 'This private trip is invitation-only.'], 403);
        if ($trip->visibility === TripVisibility::PRIVATE && (!$member->last_private_verified_at || $member->last_private_verified_at->lt(now()->subMinutes((int) config('splittrip.private_access_minutes', 30))))) {
            return response()->json(['message' => 'A fresh private-trip OTP is required.', 'code' => 'PRIVATE_OTP_REQUIRED'], 423);
        }
        if (!$member) {
            $trip->load('owner:id,name');
            return response()->json(['id' => $trip->id, 'name' => $trip->name, 'description' => $trip->description, 'visibility' => $trip->visibility, 'destination' => $trip->destination, 'start_at' => $trip->start_at, 'end_at' => $trip->end_at, 'currency' => $trip->currency, 'budget_minor' => $trip->budget_minor, 'status' => $trip->status, 'owner' => $trip->owner]);
        }
        return response()->json($trip->load(['owner:id,name', 'members.user:id,name', 'expenses.payer:id,name', 'expenses.splits.user:id,name']));
    }

    public function update(Request $request, Trip $trip, AuditLogger $audit)
    {
        $this->assertAdmin($request, $trip);
        $data = $request->validate(['name' => 'sometimes|string|max:140', 'description' => 'nullable|string|max:5000', 'budget_minor' => 'sometimes|integer|min:0|max:9000000000000000', 'start_at' => 'nullable|date', 'end_at' => 'nullable|date|after_or_equal:start_at', 'destination' => 'nullable|string|max:180', 'status' => 'sometimes|in:planning,active,completed', 'trip_type' => 'sometimes|in:event,leisure,fun_hangout', 'city_id' => 'sometimes|integer|exists:cities,id', 'photo' => 'nullable|image|max:8192']);
        return DB::transaction(function () use ($request, $trip, $data, $audit) {
            $locked = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $actor = $locked->members()->where('user_id', $request->user()->id)->where('status', 'active')->lockForUpdate()->first();
            abort_unless($actor && in_array($actor->role, ['owner', 'admin'], true), 403);
            if ($locked->visibility === TripVisibility::PRIVATE && (!$actor->last_private_verified_at || $actor->last_private_verified_at->lt(now()->subMinutes((int) config('splittrip.private_access_minutes', 30))))) abort(423, 'Private-trip OTP required.');
            if ($request->hasFile('photo')) $data['photo_path'] = $request->file('photo')->store('trips', 'public');
            unset($data['photo']);
            $locked->update($data);
            $audit->record($locked, $request->user(), 'trip.updated', 'trip', (int) $locked->id, ['changed_fields' => array_keys($data)]);
            return response()->json($locked->fresh());
        });
    }

    public function join(Request $request, Trip $trip, AuditLogger $audit)
    {
        if ($trip->visibility !== TripVisibility::PUBLIC) return response()->json(['message' => 'Private trips require an invitation.'], 403);
        return DB::transaction(function () use ($request, $trip, $audit) {
            $locked = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'completed') abort(409, 'Completed trips cannot be joined.');
            $member = TripMember::where('trip_id', $locked->id)->where('user_id', $request->user()->id)->lockForUpdate()->first();
            if ($member && $member->status === 'active') return response()->json($member, 200);
            if ($locked->participant_limit && $locked->members()->where('status', 'active')->count() >= $locked->participant_limit) abort(409, 'This trip is full.');
            if (!$member) $member = new TripMember(['trip_id' => $locked->id, 'user_id' => $request->user()->id]);
            $member->fill(['role' => 'member', 'status' => 'active', 'joined_at' => now(), 'last_private_verified_at' => null]);
            $member->save();
            $audit->record($locked, $request->user(), 'trip.joined', 'trip', (int) $locked->id);
            return response()->json($member, 201);
        });
    }

    public function leave(Request $request, Trip $trip, AuditLogger $audit)
    {
        return DB::transaction(function () use ($request, $trip, $audit) {
            $lockedTrip = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $member = $lockedTrip->members()->where('user_id', $request->user()->id)->lockForUpdate()->firstOrFail();
            if ($member->status !== 'active') return response()->noContent();
            if ($member->role === 'owner') return response()->json(['message' => 'Transfer ownership before leaving.'], 409);
            $member->update(['status' => 'left']);
            $audit->record($lockedTrip, $request->user(), 'trip.left', 'trip', (int) $lockedTrip->id);
            return response()->noContent();
        });
    }

    public function destroy(Request $request, Trip $trip, AuditLogger $audit)
    {
        $member = $trip->members()->where('user_id', $request->user()->id)->where('status', 'active')->first();
        abort_unless($member && $member->role === 'owner', 403, 'Only the trip owner can archive a trip.');
        if ($trip->visibility === TripVisibility::PRIVATE && (!$member->last_private_verified_at || $member->last_private_verified_at->lt(now()->subMinutes((int) config('splittrip.private_access_minutes', 30))))) abort(423, 'Private-trip OTP required.');
        return DB::transaction(function () use ($request, $trip, $audit) {
            $locked = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $actor = $locked->members()->where('user_id', $request->user()->id)->where('status', 'active')->lockForUpdate()->first();
            abort_unless($actor && $actor->role === 'owner' && (int) $locked->owner_id === (int) $request->user()->id, 403);
            if ($locked->visibility === TripVisibility::PRIVATE && (!$actor->last_private_verified_at || $actor->last_private_verified_at->lt(now()->subMinutes((int) config('splittrip.private_access_minutes', 30))))) abort(423, 'Private-trip OTP required.');
            $locked->delete();
            $audit->record($locked, $request->user(), 'trip.archived', 'trip', (int) $locked->id);
            return response()->noContent();
        });
    }

    private function assertAdmin(Request $request, Trip $trip): void
    {
        $member = $trip->members()->where('user_id', $request->user()->id)->where('status', 'active')->first();
        abort_unless($member && in_array($member->role, ['owner', 'admin'], true), 403);
        if ($trip->visibility === TripVisibility::PRIVATE && (!$member->last_private_verified_at || $member->last_private_verified_at->lt(now()->subMinutes((int) config('splittrip.private_access_minutes', 30))))) abort(423, 'Private-trip OTP required.');
    }
}
