<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AuthController extends Controller
{
    public function register(Request $request, AuditLogger $audit)
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email:rfc|max:255|unique:users,email', 'password' => 'required|string|min:12|confirmed']);
        $data['email'] = strtolower($data['email']);
        $user = User::create($data);
        $audit->record(null, $user, 'auth.registered', 'user', (int) $user->id);
        $verificationSent = true;
        try {
            $this->deliverVerificationLink($user);
        } catch (\Throwable $exception) {
            report($exception);
            $verificationSent = false;
        }

        return response()->json(['user' => $user, 'token' => $user->createToken('splittrip-mobile')->plainTextToken, 'verification_email_sent' => $verificationSent], 201);
    }

    public function login(Request $request, AuditLogger $audit)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $user = User::where('email', strtolower($data['email']))->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            $audit->record(null, $user, 'auth.login_failed', null, null, ['email_fingerprint' => hash('sha256', strtolower($data['email']))]);
            throw ValidationException::withMessages(['email' => ['The supplied credentials are invalid.']]);
        }
        $audit->record(null, $user, 'auth.login_succeeded', 'user', (int) $user->id);

        return response()->json(['user' => $user, 'token' => $user->createToken('splittrip-mobile')->plainTextToken]);
    }

    public function me(Request $request)
    {
        return response()->json(['user' => $request->user()]);
    }

    public function updateProfile(Request $request, AuditLogger $audit)
    {
        $data = $request->validate(['name' => 'sometimes|required|string|max:120', 'phone' => 'sometimes|nullable|string|max:32']);
        $request->user()->update($data);
        $audit->record(null, $request->user(), 'auth.profile_updated', 'user', (int) $request->user()->id, ['changed_fields' => array_keys($data)]);

        return response()->json(['user' => $request->user()->fresh()]);
    }

    public function sendVerification(Request $request)
    {
        $user = $request->user();
        if ($user->email_verified_at) {
            return response()->json(['message' => 'Email address is already verified.']);
        }
        $this->deliverVerificationLink($user);

        return response()->json(['message' => 'Verification link sent.']);
    }

    public function verifyEmail(Request $request, int $id, string $hash, AuditLogger $audit)
    {
        $user = User::findOrFail($id);
        abort_unless(hash_equals(sha1($user->email), $hash), 403);
        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
            $audit->record(null, $user, 'auth.email_verified', 'user', (int) $user->id);
        }

        return response()->json(['message' => 'Email address verified.']);
    }

    public function changePassword(Request $request, AuditLogger $audit)
    {
        $data = $request->validate(['current_password' => 'required|string', 'password' => 'required|string|min:12|confirmed']);
        if (! Hash::check($data['current_password'], $request->user()->password)) {
            throw ValidationException::withMessages(['current_password' => ['The current password is incorrect.']]);
        }
        $user = $request->user();
        $user->update(['password' => $data['password']]);
        $audit->record(null, $user, 'auth.password_changed', 'user', (int) $user->id);
        $user->tokens()->delete();

        return response()->json(['message' => 'Password changed. Sign in again with your new password.']);
    }

    private function deliverVerificationLink(User $user): void
    {
        $url = URL::temporarySignedRoute('api.v1.verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        Mail::raw("Verify your SplitTrip email address by opening this link: {$url}", fn ($mail) => $mail->to($user->email)->subject('Verify your SplitTrip email address'));
    }

    public function forgotPassword(Request $request, AuditLogger $audit)
    {
        $data = $request->validate(['email' => 'required|email']);
        $user = User::where('email', strtolower($data['email']))->first();
        if ($user) {
            $plain = Str::random(64);
            DB::table('password_reset_tokens')->updateOrInsert(['email' => $user->email], ['token' => Hash::make($plain), 'created_at' => now()]);
            Mail::raw("Your SplitTrip password reset token is: {$plain}\nIt expires in 60 minutes.", fn ($mail) => $mail->to($user->email)->subject('Reset your SplitTrip password'));
            $audit->record(null, $user, 'auth.password_reset_requested', 'user', (int) $user->id);
        }

        return response()->json(['message' => 'If the account exists, password reset instructions have been sent.']);
    }

    public function resetPassword(Request $request, AuditLogger $audit)
    {
        $data = $request->validate(['email' => 'required|email', 'token' => 'required|string|min:32', 'password' => 'required|string|min:12|confirmed']);
        $email = strtolower($data['email']);
        $row = DB::table('password_reset_tokens')->where('email', $email)->first();
        if (! $row || ! $row->created_at || now()->diffInMinutes($row->created_at) > 60 || ! Hash::check($data['token'], $row->token)) {
            throw ValidationException::withMessages(['token' => ['The password reset token is invalid or expired.']]);
        }
        DB::transaction(function () use ($email, $data) {
            $user = User::where('email', $email)->lockForUpdate()->firstOrFail();
            $user->update(['password' => $data['password']]);
            $user->tokens()->delete();
            $audit->record(null, $user, 'auth.password_reset_completed', 'user', (int) $user->id);
            DB::table('password_reset_tokens')->where('email', $email)->delete();
        });

        return response()->json(['message' => 'Password has been reset. Please sign in again.']);
    }

    public function logout(Request $request, AuditLogger $audit)
    {
        $audit->record(null, $request->user(), 'auth.logout', 'user', (int) $request->user()->id);
        $token = $request->user()->currentAccessToken();
        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }

return response()->json(['message' => 'Signed out.']);
    }
}
