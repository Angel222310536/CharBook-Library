<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\TwoFactorEmailCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TwoFactorEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_two_factor_requires_code_confirmation_before_enabling(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/me/two-factor/email/enable')
            ->assertOk()
            ->assertJsonPath('requires_confirmation', true);

        $user->refresh();
        $this->assertFalse($user->two_factor_enabled);
        $this->assertNotContains('email', $user->two_factor_methods ?? []);

        $code = null;
        Notification::assertSentTo(
            $user,
            TwoFactorEmailCodeNotification::class,
            function (TwoFactorEmailCodeNotification $notification) use (&$code) {
                $code = $notification->code;
                return preg_match('/^\d{6}$/', $notification->code) === 1;
            }
        );

        $wrongCode = $code === '000000' ? '111111' : '000000';
        $this->postJson('/api/me/two-factor/email/confirm', ['code' => $wrongCode])
            ->assertJsonValidationErrors(['code']);

        $response = $this->postJson('/api/me/two-factor/email/confirm', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('message', 'Verificacion por correo activada.');

        $user->refresh();
        $this->assertTrue($user->two_factor_enabled);
        $this->assertContains('email', $user->two_factor_methods);
        $this->assertNotEmpty($user->two_factor_recovery_codes);

        $plainRecoveryCodes = $response->json('recovery_codes');
        $this->assertIsArray($plainRecoveryCodes);
        $this->assertNotContains($plainRecoveryCodes[0], $user->two_factor_recovery_codes);
        $this->assertTrue(Hash::check($plainRecoveryCodes[0], $user->two_factor_recovery_codes[0]));

        $challenge = 'recovery-login-challenge';
        cache()->put("2fa_challenge:{$challenge}", $user->id, now()->addMinutes(10));

        $this->postJson('/api/auth/two-factor/verify', [
            'challenge' => $challenge,
            'method' => 'recovery',
            'code' => $plainRecoveryCodes[0],
        ])->assertOk()->assertJsonStructure(['token']);

        $user->refresh();
        $this->assertCount(7, $user->two_factor_recovery_codes);
    }

    public function test_email_two_factor_requires_verified_email(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/me/two-factor/email/enable')
            ->assertJsonValidationErrors(['email']);

        $user->refresh();
        $this->assertFalse($user->two_factor_enabled);
        Notification::assertNothingSent();
    }

    public function test_login_email_challenge_sends_and_verifies_real_code(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'two_factor_enabled' => true,
            'two_factor_methods' => ['email'],
        ]);
        $challenge = 'test-login-challenge';
        cache()->put("2fa_challenge:{$challenge}", $user->id, now()->addMinutes(10));

        $this->postJson('/api/auth/two-factor/challenge', [
            'challenge' => $challenge,
            'method' => 'email',
        ])->assertOk();

        $code = null;
        Notification::assertSentTo(
            $user,
            TwoFactorEmailCodeNotification::class,
            function (TwoFactorEmailCodeNotification $notification) use (&$code) {
                $code = $notification->code;
                return preg_match('/^\d{6}$/', $notification->code) === 1;
            }
        );

        $this->postJson('/api/auth/two-factor/verify', [
            'challenge' => $challenge,
            'method' => 'email',
            'code' => $code,
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'data' => ['id', 'name', 'email']]);

        $this->assertNull(cache()->get("2fa_challenge:{$challenge}"));
        $this->assertNull(cache()->get("2fa_code:{$challenge}"));
    }

    public function test_google_user_without_password_can_disable_email_two_factor_with_email_code(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'password' => null,
            'google_id' => 'google-user-1',
            'two_factor_enabled' => true,
            'two_factor_methods' => ['email'],
            'two_factor_recovery_codes' => [Hash::make('ABCDE-ABCDE')],
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/me/two-factor/disable', ['method' => 'email'])
            ->assertJsonValidationErrors(['code']);

        $this->postJson('/api/me/two-factor/email/challenge')
            ->assertOk();

        $code = null;
        Notification::assertSentTo(
            $user,
            TwoFactorEmailCodeNotification::class,
            function (TwoFactorEmailCodeNotification $notification) use (&$code) {
                $code = $notification->code;
                return preg_match('/^\d{6}$/', $notification->code) === 1;
            }
        );

        $this->postJson('/api/me/two-factor/disable', [
            'method' => 'email',
            'verification_method' => 'email',
            'code' => $code,
        ])->assertOk();

        $user->refresh();
        $this->assertFalse($user->two_factor_enabled);
        $this->assertSame([], $user->two_factor_methods);
    }

    public function test_password_user_can_disable_two_factor_with_current_password(): void
    {
        $user = User::factory()->create([
            'two_factor_enabled' => true,
            'two_factor_methods' => ['email'],
            'two_factor_recovery_codes' => [Hash::make('ABCDE-ABCDE')],
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/me/two-factor/disable', [
            'method' => 'email',
            'password' => 'password',
        ])->assertOk();

        $user->refresh();
        $this->assertFalse($user->two_factor_enabled);
        $this->assertSame([], $user->two_factor_methods);
    }

    public function test_password_user_can_disable_email_two_factor_with_email_code(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'two_factor_enabled' => true,
            'two_factor_methods' => ['email'],
            'two_factor_recovery_codes' => [Hash::make('ABCDE-ABCDE')],
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/me/two-factor/email/challenge')
            ->assertOk();

        $code = null;
        Notification::assertSentTo(
            $user,
            TwoFactorEmailCodeNotification::class,
            function (TwoFactorEmailCodeNotification $notification) use (&$code) {
                $code = $notification->code;
                return preg_match('/^\d{6}$/', $notification->code) === 1;
            }
        );

        $this->postJson('/api/me/two-factor/disable', [
            'method' => 'email',
            'verification_method' => 'email',
            'code' => $code,
        ])->assertOk();

        $user->refresh();
        $this->assertFalse($user->two_factor_enabled);
        $this->assertSame([], $user->two_factor_methods);
    }

    public function test_recent_account_email_codes_remain_valid_until_one_is_used(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'two_factor_enabled' => true,
            'two_factor_methods' => ['email'],
            'two_factor_recovery_codes' => [Hash::make('ABCDE-ABCDE')],
        ]);
        Sanctum::actingAs($user);

        $codes = [];
        $this->postJson('/api/me/two-factor/email/challenge')->assertOk();
        $this->postJson('/api/me/two-factor/email/challenge')->assertOk();

        Notification::assertSentTo(
            $user,
            TwoFactorEmailCodeNotification::class,
            function (TwoFactorEmailCodeNotification $notification) use (&$codes) {
                $codes[] = $notification->code;
                return true;
            }
        );

        $this->assertCount(2, $codes);

        $this->postJson('/api/me/two-factor/disable', [
            'method' => 'email',
            'verification_method' => 'email',
            'code' => $codes[0],
        ])->assertOk();

        $user->refresh();
        $this->assertFalse($user->two_factor_enabled);
        $this->assertSame([], $user->two_factor_methods);
    }
}
