<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TripVisibility;
use App\Models\Trip;
use App\Models\TripAccessOtp;
use App\Models\TripInvitation;
use App\Models\TripMember;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvitationController
{
    public function store(Request $r, Trip $trip, AuditLogger $audit)
    {
        $this->assertAdmin($r, $trip);
        abort_if($trip->visibility !== TripVisibility::PRIVATE, 422, 'Invitations are only used for private trips.');
        $d = $r->validate(['email' => 'required|email|max:255']);
        $plain = Str::random(64);
        $inv = DB::transaction(function () use ($trip, $r, $d, $plain) {
            $lockedTrip = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $this->assertAdmin($r, $lockedTrip);
            TripInvitation::where('trip_id', $trip->id)->where('email', strtolower($d['email']))->whereNull('accepted_at')->whereNull('revoked_at')->lockForUpdate()->update(['revoked_at' => now()]);

            return TripInvitation::create(['trip_id' => $trip->id, 'invited_by' => $r->user()->id, 'email' => strtolower($d['email']), 'token_hash' => hash('sha256', $plain), 'expires_at' => now()->addDays(7)]);
        });
        $url = rtrim((string) env('FRONTEND_APP_URL', config('app.url')), '/').'/#invite='.urlencode($plain);
        Mail::raw("You have been invited to join {$trip->name} on SplitTrip. Sign in with this email and accept the invitation: {$url}\nThis invitation expires in 7 days.", function ($m) use ($d, $trip) {
            $m->to($d['email'])->subject("Invitation to {$trip->name} on SplitTrip");
        });
        $audit->record($trip, $r->user(), 'invitation.sent', 'invitation', $inv->id, ['email_domain' => substr(strrchr($d['email'], '@') ?: '', 1)]);

        return response()->json(['message' => 'Invitation sent.', 'invitation_id' => $inv->id], 201);
    }

    public function revoke(Request $r, Trip $trip, int $invitation, AuditLogger $audit)
    {
        $this->assertAdmin($r, $trip);

        return DB::transaction(function () use ($r, $trip, $invitation, $audit) {
            $lockedTrip = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $this->assertAdmin($r, $lockedTrip);
            $locked = TripInvitation::where('trip_id', $trip->id)->whereKey($invitation)->lockForUpdate()->firstOrFail();
            abort_if($locked->accepted_at, 409, 'An accepted invitation cannot be revoked.');
            if (! $locked->revoked_at) {
                $locked->update(['revoked_at' => now()]);
                $audit->record($trip, $r->user(), 'invitation.revoked', 'invitation', (int) $locked->id);
            }

return response()->json(['message' => 'Invitation revoked.', 'invitation_id' => $locked->id]);
        });
    }

    public function accept(Request $r, AuditLogger $audit)
    {
        $d = $r->validate(['token' => 'required|string|min:32']);
        $hash = hash('sha256', $d['token']);
        $initial = TripInvitation::where('token_hash', $hash)->firstOrFail();

        return DB::transaction(function () use ($hash, $r, $audit, $initial) {
            $trip = Trip::whereKey($initial->trip_id)->lockForUpdate()->firstOrFail();
            $inv = TripInvitation::where('token_hash', $hash)->lockForUpdate()->firstOrFail();
            abort_unless((int) $inv->trip_id === (int) $trip->id, 404);
            abort_if($inv->accepted_at || $inv->revoked_at || $inv->expires_at->isPast(), 410, 'Invitation is no longer valid.');
            abort_unless(strtolower($r->user()->email) === strtolower($inv->email), 403, 'Sign in with the invited email address.');
            abort_if($trip->status === 'completed', 409, 'Completed trips cannot accept members.');
            $member = TripMember::where('trip_id', $trip->id)->where('user_id', $r->user()->id)->lockForUpdate()->first();
            if ((! $member || $member->status !== 'active') && $trip->participant_limit && $trip->members()->where('status', 'active')->count() >= $trip->participant_limit) {
                abort(409, 'This trip is full.');
            }if (! $member) {
                $member = TripMember::create(['trip_id' => $trip->id, 'user_id' => $r->user()->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now()]);
            } else {
                $member->update(['role' => 'member', 'status' => 'active', 'joined_at' => now(), 'last_private_verified_at' => null]);
            }$inv->update(['accepted_at' => now()]);
            $audit->record($trip, $r->user(), 'invitation.accepted', 'invitation', (int) $inv->id);

            return response()->json(['trip_id' => $trip->id, 'membership' => $member->fresh()], 201);
        });
    }

    public function requestOtp(Request $r, Trip $trip, AuditLogger $audit)
    {
        abort_if($trip->visibility !== TripVisibility::PRIVATE, 422);
        $member = $trip->members()->where('user_id', $r->user()->id)->where('status', 'active')->firstOrFail();
        $code = (string) random_int(100000, 999999);
        DB::transaction(function () use ($trip, $r, $code, $audit) {
            $trip->members()->where('user_id', $r->user()->id)->lockForUpdate()->firstOrFail();
            TripAccessOtp::where('trip_id', $trip->id)->where('user_id', $r->user()->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
            TripAccessOtp::create(['trip_id' => $trip->id, 'user_id' => $r->user()->id, 'code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes((int) config('splittrip.otp_ttl', 10))]);
            $audit->record($trip, $r->user(), 'private_access.otp_requested', 'trip', (int) $trip->id);
        });
        Mail::raw("Your SplitTrip access code is {$code}. It expires in ".((int) config('splittrip.otp_ttl', 10)).' minutes. If you did not request it, ignore this email.', function ($m) use ($r, $trip) {
            $m->to($r->user()->email)->subject("Your {$trip->name} access code");
        });

        return response()->json(['message' => 'A one-time code has been sent to your account email.', 'expires_in_seconds' => (int) config('splittrip.otp_ttl', 10) * 60]);
    }

    public function verifyOtp(Request $r, AuditLogger $audit)
    {
        $d = $r->validate(['trip_id' => 'required|integer|exists:trips,id', 'code' => 'required|digits:6']);
        $trip = Trip::findOrFail($d['trip_id']);
        $valid = DB::transaction(function () use ($r, $audit, $d, $trip) {
            $member = $trip->members()->where('user_id', $r->user()->id)->where('status', 'active')->lockForUpdate()->firstOrFail();
            $otp = TripAccessOtp::where('trip_id', $trip->id)->where('user_id', $r->user()->id)->whereNull('consumed_at')->latest()->lockForUpdate()->first();
            if (! $otp || $otp->expires_at->isPast() || $otp->attempts >= (int) config('splittrip.otp_max_attempts', 5)) {
                return false;
            }$otp->attempts = (int) $otp->attempts + 1;
            $otp->save();
            if (! Hash::check($d['code'], $otp->code_hash)) {
                $audit->record($trip, $r->user(), 'private_access.failed', 'trip', (int) $trip->id, ['attempts' => (int) $otp->attempts]);

                return false;
            }$otp->update(['consumed_at' => now()]);
            $member->update(['last_private_verified_at' => now()]);
            $audit->record($trip, $r->user(), 'private_access.verified', 'trip', (int) $trip->id);

            return true;
        });
        if (! $valid) {
            throw ValidationException::withMessages(['code' => ['The code is invalid or expired. Request a new code.']]);
        }

return response()->json(['message' => 'Private trip access verified.', 'verified_until' => now()->addMinutes((int) config('splittrip.private_access_minutes', 30))->toISOString()]);
    }

    private function assertAdmin(Request $r, Trip $trip): void
    {
        $m = $trip->members()->where('user_id',$r->user()->id)->where('status','active')->first();
        abort_unless($m && in_array($m->role,['owner', 'admin'],true),403);
        if ($trip->visibility === TripVisibility::PRIVATE && (! $m->last_private_verified_at || $m->last_private_verified_at->lt(now()->subMinutes((int) config('splittrip.private_access_minutes',30))))) {
            abort(423,'Private-trip OTP required.');
        }
    }
}
