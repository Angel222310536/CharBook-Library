<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Preferencias exclusivas de Premium Plus. La protección de plan la aplica el
 * middleware `entitlement:<feature>` en las rutas (seguridad server-side); aquí
 * solo validamos y persistimos. Las voces IA aún NO generan audio (placeholder).
 */
class PlusController extends Controller
{
    /** Voces IA disponibles (placeholder — sin motor de audio todavía). */
    public const VOICES = ['calida', 'juvenil', 'profunda', 'neutral'];

    /** Fondos/banners oficiales del perfil avanzado (sin subida propia por ahora). */
    public const PROFILE_BANNERS = [
        'aurora', 'biblioteca', 'oceano', 'bosque', 'pergamino',
        'noche', 'obsidiana', 'dorado', 'nebulosa', 'atelier',
    ];

    /** PATCH /api/me/plus/voice  { voice } — guarda la voz IA preferida. */
    public function setVoice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'voice' => ['required', 'string', Rule::in(self::VOICES)],
        ]);

        $user = $request->user();
        $prefs = $user->preferences ?? [];
        $prefs['reader_voice'] = $data['voice'];
        $user->update(['preferences' => $prefs]);

        return response()->json(['voice' => $data['voice'], 'voices' => self::VOICES]);
    }

    /** PATCH /api/me/plus/profile  { banner } — perfil avanzado (banner oficial). */
    public function setProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'banner' => ['nullable', 'string', Rule::in(self::PROFILE_BANNERS)],
        ]);

        $user = $request->user();
        $prefs = $user->preferences ?? [];
        $prefs['profile_banner'] = $data['banner'] ?? null;
        $user->update(['preferences' => $prefs]);

        return response()->json(['banner' => $data['banner'] ?? null, 'banners' => self::PROFILE_BANNERS]);
    }
}
