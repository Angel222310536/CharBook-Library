<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\PasswordResetCodeNotification;
use App\Notifications\VerifyEmailCodeNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Registro / Login / Recuperación con email + password (sin OAuth).
 * Compatible con el flujo Google existente — solo añade vía credenciales.
 */
class AuthController extends Controller
{
    /** POST /api/auth/register */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', 'min:2'],
            'username' => ['nullable', 'string', 'max:30', 'regex:/^[a-z0-9_]+$/i', 'unique:users,username'],
            'email' => ['required', 'email', 'max:120', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'username' => $data['username']
                ?? User::generateUniqueUsername(explode('@', $data['email'])[0]),
            'is_public_profile' => true,
        ]);

        // Asigna rol "reader" por defecto si existe
        if (\Spatie\Permission\Models\Role::where('name', 'reader')->exists()) {
            $user->assignRole('reader');
        }

        // Mandar email de verificación con código
        $this->sendEmailVerificationCode($user);

        $token = $user->createToken('charbook-web')->plainTextToken;

        return response()->json([
            'data' => $this->serializeUser($user),
            'token' => $token,
            'message' => '¡Cuenta creada! Revisa tu correo para confirmar.',
        ], 201);
    }

    /** POST /api/auth/login */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Credenciales inválidas.'],
            ]);
        }

        // Si el user tiene 2FA activo, devolvemos challenge en vez de token
        if ($user->two_factor_enabled) {
            $challenge = Str::random(40);
            cache()->put("2fa_challenge:{$challenge}", $user->id, now()->addMinutes(10));

            return response()->json([
                'two_factor_required' => true,
                'challenge' => $challenge,
                'methods' => $user->two_factor_methods ?? ['email'],
            ]);
        }

        $token = $user->createToken('charbook-web', expiresAt: $data['remember'] ?? false
            ? now()->addDays(30)
            : now()->addHours(8)
        )->plainTextToken;

        return response()->json([
            'data' => $this->serializeUser($user),
            'token' => $token,
        ]);
    }

    /** POST /api/auth/logout */
    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();
        return response()->json(['message' => 'Sesión cerrada.']);
    }

    /** POST /api/auth/forgot-password — envía email con código */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', $request->email)->first();

        // Respuesta neutra (no revelamos si el email existe)
        if (! $user) {
            return response()->json([
                'message' => 'Si la cuenta existe, recibirás un correo con instrucciones.',
            ]);
        }

        $code = (string) random_int(100000, 999999);
        cache()->put("pwd_reset:{$user->id}", Hash::make($code), now()->addMinutes(20));

        $devCode = $this->dispatchCode($user, new PasswordResetCodeNotification($code), $code, 'reset');

        $payload = [
            'message' => 'Si la cuenta existe, recibirás un correo con instrucciones.',
        ];
        if ($devCode) $payload['dev_code'] = $devCode;
        return response()->json($payload);
    }

    /** POST /api/auth/reset-password */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'code' => ['required', 'string', 'size:6'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);

        $user = User::where('email', $data['email'])->firstOrFail();
        $stored = cache()->get("pwd_reset:{$user->id}");

        if (! $stored || ! Hash::check($data['code'], $stored)) {
            throw ValidationException::withMessages([
                'code' => ['Código incorrecto o expirado.'],
            ]);
        }

        $user->update(['password' => Hash::make($data['password'])]);
        cache()->forget("pwd_reset:{$user->id}");
        // Invalida sesiones anteriores
        $user->tokens()->delete();

        return response()->json(['message' => 'Contraseña actualizada. Inicia sesión.']);
    }

    /** POST /api/auth/email/send-verification */
    public function sendEmailVerification(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->email_verified_at) {
            return response()->json(['message' => 'Tu correo ya está verificado.']);
        }
        $devCode = $this->sendEmailVerificationCode($user);
        $payload = ['message' => 'Código enviado al correo.'];
        if ($devCode) $payload['dev_code'] = $devCode;
        return response()->json($payload);
    }

    /** POST /api/auth/email/verify */
    public function verifyEmail(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'size:6']]);
        $user = $request->user();
        $stored = cache()->get("email_verify:{$user->id}");
        if (! $stored || ! Hash::check($data['code'], $stored)) {
            throw ValidationException::withMessages([
                'code' => ['Código incorrecto o expirado.'],
            ]);
        }
        $user->update(['email_verified_at' => now()]);
        cache()->forget("email_verify:{$user->id}");
        return response()->json(['message' => 'Correo verificado.']);
    }

    // ============ helpers ============

    private function sendEmailVerificationCode(User $user): ?string
    {
        $code = (string) random_int(100000, 999999);
        cache()->put("email_verify:{$user->id}", Hash::make($code), now()->addMinutes(30));
        return $this->dispatchCode($user, new VerifyEmailCodeNotification($code), $code, 'verify');
    }

    /**
     * Despacha la notificación por correo. Siempre loguea el código en
     * `storage/logs/laravel.log` por si necesitas depurar.
     *
     * Ya NO devolvemos el código en la respuesta — debe llegar al correo
     * real. Si tu .env no tiene MAIL_* configurado, usa Mailpit en local
     * (instrucciones en README).
     */
    private function dispatchCode(User $user, $notification, string $code, string $kind): ?string
    {
        Log::info("[auth:{$kind}] código {$code} para {$user->email}");

        try {
            $user->notify($notification);
        } catch (\Throwable $e) {
            Log::error('Mail falló al enviar código: ' . $e->getMessage());
        }
        return null; // nunca devolvemos al cliente
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'avatar' => $user->avatar,
            'is_email_verified' => (bool) $user->email_verified_at,
            'two_factor_enabled' => (bool) $user->two_factor_enabled,
        ];
    }
}
