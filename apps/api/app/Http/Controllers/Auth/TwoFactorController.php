<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\TwoFactorEmailCodeNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 2FA:
 *   - TOTP (Google Authenticator, Authy, 1Password...)
 *   - Email (codigos por correo)
 *   - Passkey / WebAuthn (huella, FaceID, llave fisica)
 *   - Codigos de recuperacion
 */
class TwoFactorController extends Controller
{
    private const EMAIL_CODE_TTL_MINUTES = 10;
    private const EMAIL_CODE_MAX_ATTEMPTS = 5;
    private const EMAIL_CODE_MAX_SENDS = 3;

    // ============ TOTP (app autenticadora) ============

    /** POST /api/me/two-factor/totp/setup -> devuelve secret + URL QR */
    public function totpSetup(Request $request): JsonResponse
    {
        $user = $request->user();
        $secret = $this->generateBase32Secret();
        cache()->put("totp_pending:{$user->id}", $secret, now()->addMinutes(15));

        $issuer = rawurlencode('CharBook Library');
        $label = rawurlencode($user->email);
        $otpauth = "otpauth://totp/{$issuer}:{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";

        return response()->json([
            'secret' => $secret,
            'otpauth_url' => $otpauth,
        ]);
    }

    /** POST /api/me/two-factor/totp/confirm - body: code */
    public function totpConfirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $user = $request->user();

        $pending = cache()->get("totp_pending:{$user->id}");
        if (! $pending) {
            throw ValidationException::withMessages(['code' => ['Reinicia el proceso, expiro.']]);
        }

        if (! $this->verifyTotp($pending, $data['code'])) {
            throw ValidationException::withMessages(['code' => ['Codigo incorrecto.']]);
        }

        $methods = $user->two_factor_methods ?? [];
        if (! in_array('totp', $methods, true)) $methods[] = 'totp';
        $recovery = $this->generateRecoveryCodes();

        $user->update([
            'two_factor_secret' => $pending,
            'two_factor_methods' => $methods,
            'two_factor_recovery_codes' => $this->hashRecoveryCodes($recovery),
            'two_factor_confirmed_at' => now(),
            'two_factor_enabled' => true,
        ]);
        cache()->forget("totp_pending:{$user->id}");

        return response()->json([
            'message' => 'TOTP activado.',
            'recovery_codes' => $recovery,
        ]);
    }

    // ============ Email ============

    /** POST /api/me/two-factor/email/enable */
    public function enableEmail(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->email_verified_at) {
            throw ValidationException::withMessages([
                'email' => ['Verifica tu correo antes de activar 2FA por email.'],
            ]);
        }

        $methods = $user->two_factor_methods ?? [];
        if (in_array('email', $methods, true)) {
            return response()->json([
                'message' => 'La verificacion por correo ya esta activa.',
                'requires_confirmation' => false,
            ]);
        }

        $this->sendEmailCode(
            $user,
            "2fa_email_enable:{$user->id}",
            "2fa_email_enable_send:{$user->id}",
            'activation'
        );

        return response()->json([
            'message' => 'Te enviamos un codigo a tu correo. Confirma el codigo para activar este metodo.',
            'requires_confirmation' => true,
            'expires_in_minutes' => self::EMAIL_CODE_TTL_MINUTES,
        ]);
    }

    /** POST /api/me/two-factor/email/confirm */
    public function confirmEmail(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $user = $request->user();

        if (! $this->verifyEmailCode("2fa_email_enable:{$user->id}", $data['code'])) {
            throw ValidationException::withMessages(['code' => ['Codigo incorrecto o expirado.']]);
        }

        $methods = $user->two_factor_methods ?? [];
        if (! in_array('email', $methods, true)) $methods[] = 'email';

        $existingRecoveryCodes = $user->two_factor_recovery_codes ?? [];
        $recovery = count($existingRecoveryCodes) > 0 ? null : $this->generateRecoveryCodes();

        $user->update([
            'two_factor_methods' => $methods,
            'two_factor_recovery_codes' => $recovery
                ? $this->hashRecoveryCodes($recovery)
                : $existingRecoveryCodes,
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => $user->two_factor_confirmed_at ?? now(),
        ]);

        return response()->json([
            'message' => 'Verificacion por correo activada.',
            'recovery_codes' => $recovery,
        ]);
    }

    /** POST /api/auth/two-factor/challenge - durante login: body: challenge, method */
    public function sendChallengeCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string'],
            'method' => ['required', 'in:email'],
        ]);
        $uid = cache()->get("2fa_challenge:{$data['challenge']}");
        if (! $uid) throw ValidationException::withMessages(['challenge' => ['Sesion 2FA expirada.']]);
        $user = User::findOrFail($uid);

        if (! in_array('email', $user->two_factor_methods ?? [], true)) {
            throw ValidationException::withMessages(['method' => ['Metodo 2FA no disponible para esta cuenta.']]);
        }

        $this->sendEmailCode(
            $user,
            "2fa_code:{$data['challenge']}",
            "2fa_email_login_send:{$data['challenge']}",
            'login'
        );

        return response()->json(['message' => 'Codigo enviado a tu correo.']);
    }

    /** POST /api/me/two-factor/email/challenge - para cambios sensibles en cuenta */
    public function sendAccountEmailCode(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! in_array('email', $user->two_factor_methods ?? [], true)) {
            throw ValidationException::withMessages(['method' => ['Activa 2FA por correo antes de usar este metodo.']]);
        }

        $this->sendEmailCode(
            $user,
            "2fa_account:{$user->id}",
            "2fa_email_account_send:{$user->id}",
            'account'
        );

        return response()->json([
            'message' => 'Codigo enviado a tu correo.',
            'expires_in_minutes' => self::EMAIL_CODE_TTL_MINUTES,
        ]);
    }

    /** POST /api/auth/two-factor/verify - body: challenge, code, method */
    public function verifyChallenge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string'],
            'code' => ['required', 'string'],
            'method' => ['required', 'in:totp,email,recovery'],
        ]);
        $uid = cache()->get("2fa_challenge:{$data['challenge']}");
        if (! $uid) throw ValidationException::withMessages(['challenge' => ['Sesion 2FA expirada.']]);
        $user = User::findOrFail($uid);

        $methods = $user->two_factor_methods ?? [];
        $ok = match ($data['method']) {
            'email' => in_array('email', $methods, true)
                && $this->verifyEmailCode("2fa_code:{$data['challenge']}", $data['code']),
            'totp' => in_array('totp', $methods, true)
                && $this->verifyTotp($user->two_factor_secret, $data['code']),
            'recovery' => $this->consumeRecoveryCode($user, $data['code']),
        };

        if (! $ok) throw ValidationException::withMessages(['code' => ['Codigo incorrecto.']]);

        cache()->forget("2fa_challenge:{$data['challenge']}");
        cache()->forget("2fa_code:{$data['challenge']}");

        $token = $user->createToken('charbook-web', expiresAt: now()->addDays(30))->plainTextToken;
        return response()->json([
            'token' => $token,
            'data' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ]);
    }

    // ============ Recovery codes ============

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $codes = $this->generateRecoveryCodes();
        $request->user()->update(['two_factor_recovery_codes' => $this->hashRecoveryCodes($codes)]);
        return response()->json(['recovery_codes' => $codes]);
    }

    // ============ Desactivar 2FA ============

    public function disable(Request $request): JsonResponse
    {
        $data = $request->validate([
            'method' => ['sometimes', 'in:totp,email,passkey,all'],
            'password' => ['nullable', 'string'],
            'verification_method' => ['nullable', 'in:totp,email,recovery'],
            'code' => ['nullable', 'string'],
        ]);
        $user = $request->user();
        $method = $data['method'] ?? 'all';

        if (! $this->confirmsTwoFactorChange($user, $data)) {
            $hasCodeAttempt = filled($data['verification_method'] ?? null) || filled($data['code'] ?? null);
            $field = $hasCodeAttempt || ! $user->password ? 'code' : 'password';
            $message = $hasCodeAttempt
                ? 'Codigo 2FA incorrecto o expirado. Usa el codigo mas reciente o solicita uno nuevo.'
                : ($user->password
                    ? 'Ingresa tu contrasena actual o confirma con un codigo 2FA.'
                    : 'Confirma con un codigo 2FA para desactivar este metodo.');
            throw ValidationException::withMessages([$field => [$message]]);
        }

        if ($method === 'all') {
            $user->update([
                'two_factor_enabled' => false,
                'two_factor_methods' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ]);
        } else {
            $methods = collect($user->two_factor_methods ?? [])->reject($method)->values()->all();
            $patch = ['two_factor_methods' => $methods, 'two_factor_enabled' => count($methods) > 0];
            if ($method === 'totp') $patch['two_factor_secret'] = null;
            $user->update($patch);
        }
        return response()->json(['message' => '2FA actualizada.']);
    }

    // ============ helpers ============

    private function generateBase32Secret(int $length = 32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[random_int(0, 31)];
        }
        return $secret;
    }

    private function generateRecoveryCodes(int $count = 8): array
    {
        return collect(range(1, $count))
            ->map(fn () => strtoupper(Str::random(5)) . '-' . strtoupper(Str::random(5)))
            ->all();
    }

    private function hashRecoveryCodes(array $codes): array
    {
        return collect($codes)
            ->map(fn (string $code) => Hash::make(strtoupper(trim($code))))
            ->all();
    }

    private function sendEmailCode(User $user, string $cacheKey, string $rateLimitKey, string $context): void
    {
        if (RateLimiter::tooManyAttempts($rateLimitKey, self::EMAIL_CODE_MAX_SENDS)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            throw ValidationException::withMessages([
                'email' => ["Demasiados codigos enviados. Intenta de nuevo en {$seconds} segundos."],
            ]);
        }

        $code = (string) random_int(100000, 999999);
        $stored = cache()->get($cacheKey);
        $hashes = is_array($stored) ? $stored : ($stored ? [$stored] : []);
        $hashes[] = $this->emailCodeHash($cacheKey, $code);
        $hashes = array_slice($hashes, -self::EMAIL_CODE_MAX_SENDS);

        cache()->put($cacheKey, $hashes, now()->addMinutes(self::EMAIL_CODE_TTL_MINUTES));
        cache()->forget("{$cacheKey}:attempts");
        RateLimiter::hit($rateLimitKey, self::EMAIL_CODE_TTL_MINUTES * 60);

        try {
            $user->notify(new TwoFactorEmailCodeNotification($code));
        } catch (\Throwable $e) {
            cache()->forget($cacheKey);
            Log::warning('No se pudo enviar codigo 2FA por correo.', [
                'user_id' => $user->id,
                'context' => $context,
                'error' => $e->getMessage(),
            ]);
            throw ValidationException::withMessages([
                'email' => ['No pudimos enviar el codigo. Revisa la configuracion SMTP e intenta de nuevo.'],
            ]);
        }
    }

    private function verifyEmailCode(string $cacheKey, string $code): bool
    {
        $attemptKey = "{$cacheKey}:attempts";
        $attempts = (int) cache()->get($attemptKey, 0);
        if ($attempts >= self::EMAIL_CODE_MAX_ATTEMPTS) {
            cache()->forget($cacheKey);
            return false;
        }

        $stored = cache()->get($cacheKey);
        if (! $stored) return false;

        $matches = collect(is_array($stored) ? $stored : [$stored])
            ->contains(function (string $storedCode) use ($cacheKey, $code) {
                if (hash_equals($this->emailCodeHash($cacheKey, $code), $storedCode)) {
                    return true;
                }

                $info = password_get_info($storedCode);
                $isLegacyHash = ($info['algoName'] ?? 'unknown') !== 'unknown';
                return $isLegacyHash && Hash::check($code, $storedCode);
            });

        if (! $matches) {
            cache()->put($attemptKey, $attempts + 1, now()->addMinutes(self::EMAIL_CODE_TTL_MINUTES));
            if (($attempts + 1) >= self::EMAIL_CODE_MAX_ATTEMPTS) {
                cache()->forget($cacheKey);
            }
            return false;
        }

        cache()->forget($cacheKey);
        cache()->forget($attemptKey);
        return true;
    }

    private function emailCodeHash(string $cacheKey, string $code): string
    {
        return hash_hmac('sha256', "{$cacheKey}|{$code}", (string) config('app.key'));
    }

    private function confirmsTwoFactorChange(User $user, array $data): bool
    {
        $password = trim((string) ($data['password'] ?? ''));
        if ($password !== '' && $user->password && Hash::check($password, $user->password)) {
            return true;
        }

        $method = $data['verification_method'] ?? null;
        $code = trim((string) ($data['code'] ?? ''));
        if (! $method || $code === '') {
            return false;
        }

        $methods = $user->two_factor_methods ?? [];
        return match ($method) {
            'email' => in_array('email', $methods, true)
                && $this->verifyEmailCode("2fa_account:{$user->id}", $code),
            'totp' => in_array('totp', $methods, true)
                && $this->verifyTotp($user->two_factor_secret, $code),
            'recovery' => $this->consumeRecoveryCode($user, $code),
            default => false,
        };
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];
        $normalized = strtoupper(trim($code));

        foreach ($codes as $idx => $stored) {
            $stored = (string) $stored;
            $info = password_get_info($stored);
            $isHashed = ($info['algoName'] ?? 'unknown') !== 'unknown';
            $matches = $isHashed
                ? Hash::check($normalized, (string) $stored)
                : hash_equals(strtoupper(trim((string) $stored)), $normalized);

            if ($matches) {
                unset($codes[$idx]);
                $user->update(['two_factor_recovery_codes' => array_values($codes)]);
                return true;
            }
        }

        return false;
    }

    /** TOTP RFC 6238, ventana +/-1 slot. */
    private function verifyTotp(?string $secret, string $code, int $window = 1): bool
    {
        if (! $secret) return false;
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6) return false;
        $time = floor(time() / 30);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals($this->computeTotp($secret, $time + $i), $code)) return true;
        }
        return false;
    }

    private function computeTotp(string $base32Secret, int $time): string
    {
        $key = $this->base32Decode($base32Secret);
        $bin = pack('N*', 0) . pack('N*', $time);
        $hmac = hash_hmac('sha1', $bin, $key, true);
        $offset = ord($hmac[19]) & 0xF;
        $code = (
            ((ord($hmac[$offset]) & 0x7F) << 24) |
            ((ord($hmac[$offset + 1]) & 0xFF) << 16) |
            ((ord($hmac[$offset + 2]) & 0xFF) << 8) |
            (ord($hmac[$offset + 3]) & 0xFF)
        ) % 1000000;
        return str_pad((string) $code, 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $b32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32 = strtoupper(rtrim($b32, '='));
        $bits = '';
        foreach (str_split($b32) as $c) {
            $i = strpos($alphabet, $c);
            if ($i === false) continue;
            $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) $out .= chr(bindec($byte));
        }
        return $out;
    }
}
