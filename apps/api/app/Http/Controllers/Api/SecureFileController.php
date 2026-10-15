<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Services\UnlockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Contenido protegido en Cloudflare R2 (EPUB/PDF/audio/imágenes premium):
 * SOLO se sirve mediante URLs firmadas temporales tras validar el derecho.
 * Los archivos nunca se exponen públicamente.
 */
class SecureFileController extends Controller
{
    public function __construct(private readonly UnlockService $unlocks) {}

    /** GET /books/{slug}/secure-file — URL firmada (15 min) si el usuario tiene derecho. */
    public function bookFile(Request $request, Book $book): JsonResponse
    {
        $user = $request->user();

        // Derecho: libro gratuito, o suscripción Plus en catálogo ilimitado,
        // o TODOS los capítulos de pago desbloqueados (compra completa).
        $allowed = $book->access_type === 'free';

        if (! $allowed) {
            $plan = $this->unlocks->activePlan($user);
            $allowed = $plan === 'premium_plus' && $book->in_unlimited_catalog;
        }

        if (! $allowed) {
            $paidChapters = $book->chapters()->where('is_published', true)->where('coin_price', '>', 0)->pluck('id');
            if ($paidChapters->isNotEmpty()) {
                $unlocked = \App\Models\Unlock::where('user_id', $user->id)
                    ->whereIn('chapter_id', $paidChapters)
                    ->count();
                $allowed = $unlocked >= $paidChapters->count();
            }
        }

        abort_unless($allowed, 403, 'No tienes acceso a este archivo.');

        $path = $book->file_path;
        abort_unless($path, 404, 'Esta obra no tiene archivo.');

        // R2 (S3-compatible): URL firmada temporal de 15 minutos
        if ($book->file_path && str_starts_with((string) $book->file_link, 'http') === false || config('filesystems.disks.r2_books.bucket')) {
            try {
                $url = Storage::disk('r2_books')->temporaryUrl($path, now()->addMinutes(15));
                return response()->json(['url' => $url, 'expires_in' => 900]);
            } catch (\Throwable) {
                // sin R2 en dev: cae al proxy local firmado
            }
        }

        // Fallback dev (disco local): ruta firmada temporal de Laravel
        $url = URL::temporarySignedRoute('books.file-proxy.signed', now()->addMinutes(15), ['book' => $book->slug]);

        return response()->json(['url' => $url, 'expires_in' => 900]);
    }
}
