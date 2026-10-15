<?php

namespace App\Http\Controllers\Api\Me;

use App\Http\Controllers\Controller;
use App\Services\FileGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Endpoints de cuenta: subir avatar, cambiar contraseña, preferencias, suscripción…
 */
class AccountController extends Controller
{
    /** POST /api/me/avatar (multipart) */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);

        // Validación de seguridad real (MIME + getimagesize). Alineado a JPG/PNG/WebP.
        app(FileGuard::class)->validateImage($request->file('avatar'), 'avatar');

        $user = $request->user();
        $disk = config('filesystems.public_disk', 'public');
        $path = $request->file('avatar')->store('avatars/' . $user->id, $disk);

        // Si Storage tiene URL pública, usarla; si no, dejar path relativo.
        try {
            $url = Storage::disk($disk)->url($path);
        } catch (\Throwable) {
            $url = '/storage/' . $path;
        }

        $user->update(['avatar' => $url]);

        return response()->json([
            'avatar' => $url,
            'message' => 'Foto de perfil actualizada.',
        ]);
    }

    /** PATCH /api/me/account — actualiza name/username/bio */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:80'],
            'username' => [
                'sometimes', 'string', 'max:30',
                'regex:/^[a-z0-9_]+$/i',
                'unique:users,username,' . $request->user()->id,
            ],
            'bio' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_public_profile' => ['sometimes', 'boolean'],
        ]);
        $request->user()->update($data);
        return response()->json([
            'data' => $request->user()->fresh(),
            'message' => 'Perfil actualizado.',
        ]);
    }

    /** PATCH /api/me/password */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['nullable', 'current_password'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);
        $user = $request->user();
        // Si el usuario ya tenía password, exigir current
        if ($user->password && empty($data['current_password'])) {
            throw ValidationException::withMessages([
                'current_password' => ['La contraseña actual es obligatoria.'],
            ]);
        }
        $user->update(['password' => \Hash::make($data['password'])]);
        return response()->json(['message' => 'Contraseña actualizada.']);
    }

    /** PATCH /api/me/preferences */
    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'theme' => ['sometimes', 'in:light,dark,system'],
            'reading_font' => ['sometimes', 'in:serif,sans,mono'],
            'reading_size' => ['sometimes', 'integer', 'min:80', 'max:160'],
            'email_frequency' => ['sometimes', 'in:realtime,daily,weekly,never'],
            'push_notifications' => ['sometimes', 'boolean'],
            'content_filter' => ['sometimes', 'in:safe,standard,explicit'],
            'autoplay_audio' => ['sometimes', 'boolean'],
            'locale' => ['sometimes', 'string', 'max:5'],
        ]);

        $user = $request->user();
        $prefs = array_merge($user->preferences ?? [], $data);
        $user->update(['preferences' => $prefs]);

        return response()->json(['data' => $prefs, 'message' => 'Preferencias guardadas.']);
    }

    /** GET /api/me/sessions — lista tokens activos */
    public function sessions(Request $request): JsonResponse
    {
        $current = $request->user()->currentAccessToken();
        $sessions = $request->user()->tokens()->orderByDesc('created_at')->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'last_used_at' => $t->last_used_at?->toIso8601String(),
                'created_at' => $t->created_at?->toIso8601String(),
                'current' => $current && $t->id === $current->id,
            ]);
        return response()->json(['data' => $sessions]);
    }

    /** DELETE /api/me/sessions/{id} */
    public function revokeSession(Request $request, int $id): JsonResponse
    {
        $request->user()->tokens()->where('id', $id)->delete();
        return response()->json(['message' => 'Sesión cerrada.']);
    }

    /** GET /api/me/subscription */
    public function subscription(Request $request): JsonResponse
    {
        $user = $request->user();
        return response()->json([
            'data' => [
                'tier' => $user->subscription_tier ?? 'free',
                'ends_at' => $user->subscription_ends_at?->toIso8601String(),
                'tiers_available' => [
                    ['key' => 'free', 'name' => 'Gratis', 'price' => 0, 'features' => ['Catálogo público', 'Reseñas', 'Comunidad']],
                    ['key' => 'plus', 'name' => 'Plus', 'price' => 4.99, 'features' => ['Sin publicidad', 'Modo lectura premium', 'Bookmarks ilimitados']],
                    ['key' => 'pro', 'name' => 'Pro', 'price' => 9.99, 'features' => ['Todo de Plus', 'Acceso anticipado', 'Apoya creadores con tips']],
                ],
            ],
        ]);
    }

    /** DELETE /api/me/account — soft-delete */
    public function deleteAccount(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required_with:has_password', 'current_password'],
            'confirm' => ['required', 'in:ELIMINAR'],
        ]);
        $user = $request->user();
        $user->tokens()->delete();
        $user->delete();
        return response()->json(['message' => 'Tu cuenta ha sido eliminada.']);
    }
}
