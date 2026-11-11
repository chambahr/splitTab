<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TripVisibility;
use App\Models\Trip;
use App\Models\TripMember;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TripMemberController
{
    public function index(Request $request, Trip $trip)
    {
        $this->assertCanView($request, $trip);

        return response()->json(
            $trip->members()
                ->where('status', 'active')
                ->with('user:id,name')
                ->orderByRaw(
                    "CASE role
                        WHEN 'owner' THEN 0
                        WHEN 'admin' THEN 1
                        WHEN 'member' THEN 2
                        ELSE 3
                    END"
                )
                ->get()
        );
    }

    public function updateRole(
        Request $request,
        Trip $trip,
        int $member,
        AuditLogger $audit
    ) {
        $actor = $this->assertOwner($request, $trip);

        $data = $request->validate([
            'role' => ['required', Rule::in(['admin', 'member', 'viewer'])],
        ]);

        return DB::transaction(function () use (
            $trip,
            $member,
            $data,
            $actor,
            $request,
            $audit
        ) {
            $lockedTrip = Trip::query()
                ->lockForUpdate()
                ->findOrFail($trip->id);
            $lockedActor = $lockedTrip->members()->where('user_id', $request->user()->id)->where('status', 'active')->lockForUpdate()->first();
            abort_unless($lockedActor && $lockedActor->role === 'owner' && (int) $lockedTrip->owner_id === (int) $request->user()->id, 403);
            $this->assertPrivateAccessIfNeeded($lockedTrip, $lockedActor);

            $target = $lockedTrip->members()
                ->lockForUpdate()
                ->whereKey($member)
                ->where('status', 'active')
                ->firstOrFail();

            abort_if(
                $target->role === 'owner',
                409,
                'Transfer ownership before changing the owner role.'
            );

            abort_if(
                (int) $target->user_id === (int) $actor->user_id,
                409,
                'The owner cannot change their own role.'
            );

            $previousRole = $target->role;

            $target->update([
                'role' => $data['role'],
            ]);

            $audit->record(
                $lockedTrip,
                $request->user(),
                'trip.member_role_updated',
                'trip_member',
                (int) $target->id,
                [
                    'member_id' => (int) $target->id,
                    'member_user_id' => (int) $target->user_id,
                    'previous_role' => $previousRole,
                    'new_role' => $data['role'],
                ]
            );

            return response()->json(
                $target->fresh()->load('user:id,name,email')
            );
        });
    }

    public function remove(
        Request $request,
        Trip $trip,
        int $member,
        AuditLogger $audit
    ) {
        $actor = $this->assertAdmin($request, $trip);

        return DB::transaction(function () use (
            $trip,
            $member,
            $actor,
            $request,
            $audit
        ) {
            $lockedTrip = Trip::query()
                ->lockForUpdate()
                ->findOrFail($trip->id);
            $lockedActor = $lockedTrip->members()->where('user_id', $request->user()->id)->where('status', 'active')->lockForUpdate()->first();
            abort_unless($lockedActor && in_array($lockedActor->role, ['owner', 'admin'], true), 403);
            $this->assertPrivateAccessIfNeeded($lockedTrip, $lockedActor);

            $target = $lockedTrip->members()
                ->lockForUpdate()
                ->whereKey($member)
                ->where('status', 'active')
                ->firstOrFail();

            abort_if(
                $target->role === 'owner',
                409,
                'The trip owner cannot be removed.'
            );

            abort_if(
                (int) $target->user_id === (int) $actor->user_id,
                409,
                'Use the leave-trip endpoint to leave this trip.'
            );

            abort_if(
                $lockedActor->role === 'admin'
                    && ! in_array($target->role, ['member', 'viewer'], true),
                403,
                'Admins can only remove members and viewers.'
            );

            $previousRole = $target->role;

            $target->update([
                'status' => 'removed',
            ]);

            $audit->record(
                $lockedTrip,
                $request->user(),
                'trip.member_removed',
                'trip_member',
                (int) $target->id,
                [
                    'member_id' => (int) $target->id,
                    'removed_user_id' => (int) $target->user_id,
                    'previous_role' => $previousRole,
                ]
            );

            return response()->noContent();
        });
    }

    public function transferOwnership(
        Request $request,
        Trip $trip,
        AuditLogger $audit
    ) {
        $actor = $this->assertOwner($request, $trip);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
        ]);

        return DB::transaction(function () use (
            $trip,
            $data,
            $actor,
            $request,
            $audit
        ) {
            $lockedTrip = Trip::query()
                ->lockForUpdate()
                ->findOrFail($trip->id);
            $lockedActor = $lockedTrip->members()->where('user_id', $request->user()->id)->where('status', 'active')->lockForUpdate()->first();
            abort_unless($lockedActor && $lockedActor->role === 'owner' && (int) $lockedTrip->owner_id === (int) $request->user()->id, 403);
            $this->assertPrivateAccessIfNeeded($lockedTrip, $lockedActor);

            $currentOwner = $lockedTrip->members()
                ->lockForUpdate()
                ->where('user_id', $request->user()->id)
                ->where('role', 'owner')
                ->where('status', 'active')
                ->firstOrFail();

            $newOwner = $lockedTrip->members()
                ->lockForUpdate()
                ->where('user_id', $data['user_id'])
                ->where('status', 'active')
                ->first();

            abort_unless(
                $newOwner,
                422,
                'The new owner must already be an active trip member.'
            );

            abort_if(
                (int) $newOwner->user_id === (int) $actor->user_id,
                422,
                'The selected user is already the owner.'
            );

            $previousOwnerId = (int) $currentOwner->user_id;
            $newOwnerId = (int) $newOwner->user_id;

            $currentOwner->update([
                'role' => 'admin',
            ]);

            $newOwner->update([
                'role' => 'owner',
            ]);

            $lockedTrip->update([
                'owner_id' => $newOwnerId,
            ]);

            $audit->record(
                $lockedTrip,
                $request->user(),
                'trip.ownership_transferred',
                'trip',
                (int) $lockedTrip->id,
                [
                    'previous_owner_id' => $previousOwnerId,
                    'new_owner_id' => $newOwnerId,
                ]
            );

            return response()->json([
                'trip' => $lockedTrip->fresh(),
                'previous_owner' => $currentOwner
                    ->fresh()
                    ->load('user:id,name,email'),
                'new_owner' => $newOwner
                    ->fresh()
                    ->load('user:id,name,email'),
            ]);
        });
    }

    private function assertCanView(
        Request $request,
        Trip $trip
    ): ?TripMember {
        $member = $trip->members()
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->first();

        if ($trip->visibility === TripVisibility::PRIVATE) {
            abort_unless(
                $member,
                403,
                'This private trip is invitation-only.'
            );

            $this->assertPrivateAccess($member);
        }

        abort_unless(
            $member || $trip->visibility === TripVisibility::PUBLIC,
            403
        );

        return $member;
    }

    private function assertAdmin(
        Request $request,
        Trip $trip
    ): TripMember {
        $member = $trip->members()
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->first();

        abort_unless(
            $member && in_array($member->role, ['owner', 'admin'], true),
            403
        );

        $this->assertPrivateAccessIfNeeded($trip, $member);

        return $member;
    }

    private function assertOwner(
        Request $request,
        Trip $trip
    ): TripMember {
        $member = $trip->members()
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->first();

        abort_unless(
            $member
                && $member->role === 'owner'
                && (int) $trip->owner_id === (int) $request->user()->id,
            403
        );

        $this->assertPrivateAccessIfNeeded($trip, $member);

        return $member;
    }

    private function assertPrivateAccessIfNeeded(
        Trip $trip,
        TripMember $member
    ): void {
        if ($trip->visibility === TripVisibility::PRIVATE) {
            $this->assertPrivateAccess($member);
        }
    }

    private function assertPrivateAccess(TripMember $member): void
    {
        abort_unless(
            $member->last_private_verified_at
                && $member->last_private_verified_at->gte(
                    now()->subMinutes(
                        (int) config('splittrip.private_access_minutes', 30)
                    )
                ),
            423,
            'Private-trip OTP required.'
        );
    }
}
