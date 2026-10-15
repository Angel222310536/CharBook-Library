<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AffiliateLinkResource;
use App\Models\AffiliateClick;
use App\Models\AffiliateLink;
use App\Models\Book;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AffiliateLinkController extends Controller
{
    // =========================================================
    // ============ PÚBLICO ============
    // =========================================================

    /**
     * GET /api/books/{slug}/affiliate-links
     * Lista los enlaces activos del libro (público).
     */
    public function indexForBook(Book $book): JsonResponse
    {
        $links = $book->affiliateLinks; // ya ordenado y filtrado por is_active
        return AffiliateLinkResource::collection($links)->response();
    }

    /**
     * GET /api/affiliate-links/{link}/go
     * Loguea el click + redirige al vendor.
     * Es un GET para que `<a href="...">` funcione directo desde el navegador.
     */
    public function track(Request $request, AffiliateLink $link): RedirectResponse
    {
        if (! $link->is_active) {
            abort(410, 'Enlace inactivo.');
        }

        // Trackeo asíncrono — preferimos no bloquear la redirección
        try {
            AffiliateClick::create([
                'affiliate_link_id' => $link->id,
                'user_id' => optional($request->user())->id,
                'ip_hash' => hash('sha256', $request->ip() . config('app.key')),
                'referrer' => substr((string) $request->header('referer'), 0, 500),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'created_at' => now(),
            ]);
            $link->increment('click_count');
        } catch (\Throwable) {
            // Si el log falla, seguimos con la redirección de todos modos
        }

        // 302 redirect al vendor (nofollow vía headers no aplica a redirects,
        // se gestiona en el <a rel="nofollow"> del frontend)
        return redirect()->away($link->url);
    }

    // =========================================================
    // ============ ADMIN ============
    // =========================================================

    /**
     * GET /api/admin/books/{slug}/affiliate-links
     * Todos los enlaces (activos e inactivos) del libro.
     */
    public function adminIndex(Book $book): JsonResponse
    {
        $links = AffiliateLink::where('book_id', $book->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return AffiliateLinkResource::collection($links)->response();
    }

    /**
     * POST /api/admin/books/{slug}/affiliate-links
     */
    public function store(Request $request, Book $book): JsonResponse
    {
        $data = $request->validate([
            'vendor' => 'required|in:' . implode(',', AffiliateLink::VENDORS),
            'url' => 'required|url|max:1000',
            'label' => 'nullable|string|max:100',
            'position' => 'nullable|integer|min:0|max:1000',
            'is_active' => 'boolean',
        ]);

        $data['book_id'] = $book->id;
        $link = AffiliateLink::create($data);

        return (new AffiliateLinkResource($link))->response()->setStatusCode(201);
    }

    /**
     * PATCH /api/admin/affiliate-links/{link}
     */
    public function update(Request $request, AffiliateLink $link): JsonResponse
    {
        $data = $request->validate([
            'vendor' => 'sometimes|required|in:' . implode(',', AffiliateLink::VENDORS),
            'url' => 'sometimes|required|url|max:1000',
            'label' => 'nullable|string|max:100',
            'position' => 'nullable|integer|min:0|max:1000',
            'is_active' => 'boolean',
        ]);

        $link->update($data);

        return (new AffiliateLinkResource($link))->response();
    }

    /**
     * DELETE /api/admin/affiliate-links/{link}
     */
    public function destroy(AffiliateLink $link): JsonResponse
    {
        $link->delete();
        return response()->json(['message' => 'Enlace eliminado.']);
    }
}
