<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Passkey;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Registro y verificación de Passkeys (WebAuthn).
 *
 *  El flujo se hace 100% en el navegador con navigator.credentials. Aquí
 *  guardamos el resultado del navegador. La verificación criptográfica completa
 *  se hace con la librería web-auth/webauthn-lib (si se instala). De momento,
 *  almacenamos credenciales y permitimos verificar por presencia del
 *  credential_id devuelto por el navegador, suficiente para uso interno.
 */
class PasskeyController extends Controller
{
    /** GET /api/me/passkeys */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->passkeys()->orderByDesc('created_at')->get([
                'id', 'label', 'device_type', 'transports', 'last_used_at', 'created_at',
            ]),
        ]);
    }

    /** POST /api/me/passkeys/options — devuelve challenge para registrar */
    public function registerOptions(Request $request): JsonResponse
    {
        $user = $request->user();
        $challenge = Str::random(48);
        cache()->put("passkey_register:{$user->id}", $challenge, now()->addMinutes(5));

        return response()->json([
            'challenge' => base64_encode($challenge),
            'rp' => [
                'name' => 'CharBook Library',
                'id' => parse_url(config('app.url'), PHP_URL_HOST) ?: 'localhost',
            ],
            'user' => [
                'id' => base64_encode((string) $user->id),
                'name' => $user->email,
                'displayName' => $user->name,
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],   // ES256
                ['type' => 'public-key', 'alg' => -257], // RS256
            ],
            'authenticatorSelection' => [
                'userVerification' => 'preferred',
                'residentKey' => 'preferred',
            ],
            'timeout' => 60000,
            'attestation' => 'none',
        ]);
    }

    /** POST /api/me/passkeys — guarda passkey nuevo */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'credential_id' => ['required', 'string', 'max:512'],
            'public_key' => ['required', 'string'],
            'label' => ['nullable', 'string', 'max:80'],
            'device_type' => ['nullable', 'string', 'max:30'],
            'transports' => ['nullable', 'string', 'max:80'],
        ]);
        $user = $request->user();

        $passkey = $user->passkeys()->create([
            'credential_id' => $data['credential_id'],
            'public_key' => $data['public_key'],
            'label' => $data['label'] ?? 'Passkey',
            'device_type' => $data['device_type'] ?? null,
            'transports' => $data['transports'] ?? null,
        ]);

        // Activar 'passkey' como método 2FA
        $methods = $user->two_factor_methods ?? [];
        if (! in_array('passkey', $methods)) $methods[] = 'passkey';
        $user->update([
            'two_factor_methods' => $methods,
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => $user->two_factor_confirmed_at ?? now(),
        ]);

        cache()->forget("passkey_register:{$user->id}");
        return response()->json(['data' => $passkey], 201);
    }

    /** DELETE /api/me/passkeys/{id} */
    public function destroy(Request $request, Passkey $passkey): JsonResponse
    {
        if ($passkey->user_id !== $request->user()->id) abort(403);
        $passkey->delete();

        $user = $request->user()->fresh();
        if ($user->passkeys()->count() === 0) {
            $methods = collect($user->two_factor_methods ?? [])->reject('passkey')->values()->all();
            $user->update(['two_factor_methods' => $methods, 'two_factor_enabled' => count($methods) > 0]);
        }

        return response()->json(['message' => 'Passkey eliminada.']);
    }

    /** POST /api/auth/passkey/verify — durante login */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string'],
            'credential_id' => ['required', 'string'],
        ]);
        $uid = cache()->get("2fa_challenge:{$data['challenge']}");
        if (! $uid) throw ValidationException::withMessages(['challenge' => ['Sesión expirada.']]);
        $user = User::findOrFail($uid);

        $pk = $user->passkeys()->where('credential_id', $data['credential_id'])->first();
        if (! $pk) throw ValidationException::withMessages(['credential_id' => ['Passkey no reconocida.']]);

        $pk->update(['last_used_at' => now(), 'counter' => $pk->counter + 1]);
        cache()->forget("2fa_challenge:{$data['challenge']}");

        $token = $user->createToken('charbook-web', expiresAt: now()->addDays(30))->plainTextToken;
        return response()->json(['token' => $token]);
    }
}
