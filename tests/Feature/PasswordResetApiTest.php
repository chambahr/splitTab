<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class PasswordResetApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_reset_consumes_token_revokes_sessions_and_audits_action(): void
    {
        $user = User::factory()->create(['email' => 'reset@example.com']);
        $user->createToken('old-device');
        $plainToken = str_repeat('a', 64);
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make($plainToken),
            'created_at' => now(),
        ]);

        $this->postJson('/api/v1/reset-password', [
            'email' => strtoupper($user->email),
            'token' => $plainToken,
            'password' => 'A-strong-new-password-2026',
            'password_confirmation' => 'A-strong-new-password-2026',
        ])->assertOk();

        $this->assertTrue(Hash::check('A-strong-new-password-2026', $user->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'auth.password_reset_completed',
        ]);
    }

    public function test_password_reset_token_cannot_be_reused(): void
    {
        $user = User::factory()->create(['email' => 'replay@example.com']);
        $plainToken = str_repeat('b', 64);
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make($plainToken),
            'created_at' => now(),
        ]);

        $payload = [
            'email' => $user->email,
            'token' => $plainToken,
            'password' => 'A-strong-new-password-2026',
            'password_confirmation' => 'A-strong-new-password-2026',
        ];

        $this->postJson('/api/v1/reset-password', $payload)->assertOk();
        $this->postJson('/api/v1/reset-password', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');
    }

    public function test_expired_password_reset_token_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'expired@example.com']);
        $plainToken = str_repeat('c', 64);
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make($plainToken),
            'created_at' => now()->subHour()->subSecond(),
        ]);

        $this->postJson('/api/v1/reset-password', [
            'email' => $user->email,
            'token' => $plainToken,
            'password' => 'A-strong-new-password-2026',
            'password_confirmation' => 'A-strong-new-password-2026',
        ])->assertUnprocessable()->assertJsonValidationErrors('token');

        $this->assertFalse(Hash::check('A-strong-new-password-2026', $user->fresh()->password));
    }
}
