<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Book;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Panel admin para moderar las obras que suben los creadores.
 *
 * "Obras subidas" excluye las que cargaron los administradores directamente
 * desde el panel de Libros: solo nos importan las que tienen creator_id.
 */
class AdminUploadedWorksController extends Controller
{
    public const MOD_ACTIONS = [
        'hide', 'unhide', 'review', 'sensitive', 'unsensitive',
        'pin', 'unpin', 'recommend', 'unrecommend', 'trend', 'untrend',
    ];

    public const AGE_RATINGS = ['all', 'teen', 'mature', 'explicit'];

    /**
     * GET /api/admin/uploaded-works
     *
     * Filtros opcionales:
     *   status     → submission_status (published | review | draft)
     *   visibility → public | hidden | sensitive | featured
     *   sort       → oldest | newest (default: oldest, según pedido)
     *   search     → en title del libro
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $visibility = $request->query('visibility');
        $sort = $request->query('sort', 'oldest');
        $search = trim((string) $request->query('search', ''));

        $query = Book::query()
            ->whereNotNull('creator_id')
            ->with([
                'creator:id,name,username,avatar,creator_status,creator_tier',
                'categories:id,name,slug',
            ]);

        if ($status && $status !== 'all') {
            $query->where('submission_status', $status);
        }

        if ($visibility === 'hidden')      $query->where('is_hidden', true);
        if ($visibility === 'public')      $query->where('is_hidden', false);
        if ($visibility === 'sensitive')   $query->where('is_sensitive', true);
        if ($visibility === 'featured') {
            $query->where(function ($q) {
                $q->where('is_pinned', true)
                    ->orWhere('is_recommended', true)
                    ->orWhere('is_trending', true);
            });
        }

        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $query->where('title', 'ILIKE', $like);
        }

        $query->orderBy('created_at', $sort === 'newest' ? 'desc' : 'asc');

        $works = $query->paginate(20)->withQueryString();

        return response()->json([
            'data' => $works->getCollection()->map(fn (Book $b) => $this->serialize($b)),
            'meta' => [
                'current_page' => $works->currentPage(),
                'last_page' => $works->lastPage(),
                'per_page' => $works->perPage(),
                'total' => $works->total(),
            ],
        ]);
    }

    /**
     * GET /api/admin/uploaded-works/{work}
     * Detalle (para drawer con todas las acciones).
     */
    public function show(Request $request, Book $work): JsonResponse
    {
        $work->load([
            'creator:id,name,username,avatar,creator_status,creator_tier',
            'categories:id,name,slug',
            'author:id,name,slug',
        ]);

        return response()->json(['data' => $this->serialize($work, full: true)]);
    }

    /**
     * PATCH /api/admin/uploaded-works/{work}/moderation
     * Acción de moderación.
     */
    public function moderate(Request $request, Book $work): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(self::MOD_ACTIONS)],
        ]);

        $admin = $request->user();

        match ($data['action']) {
            'hide' => $work->forceFill([
                'is_hidden' => true,
                'is_published' => false,
                'hidden_at' => now(),
                'hidden_by' => $admin->id,
            ])->save(),
            'unhide' => $work->forceFill([
                'is_hidden' => false,
                'is_published' => true,
                'submission_status' => 'published',
                'hidden_at' => null,
                'hidden_by' => null,
            ])->save(),
            'review' => $work->forceFill([
                'submission_status' => 'review',
                'is_published' => false,
            ])->save(),
            'sensitive' => $work->forceFill(['is_sensitive' => true])->save(),
            'unsensitive' => $work->forceFill(['is_sensitive' => false])->save(),
            'pin' => $work->forceFill(['is_pinned' => true, 'is_featured' => true])->save(),
            'unpin' => $work->forceFill(['is_pinned' => false])->save(),
            'recommend' => $work->forceFill(['is_recommended' => true])->save(),
            'unrecommend' => $work->forceFill(['is_recommended' => false])->save(),
            'trend' => $work->forceFill(['is_trending' => true])->save(),
            'untrend' => $work->forceFill(['is_trending' => false])->save(),
        };

        return response()->json([
            'data' => $this->serialize($work->fresh()),
            'message' => "Acción '{$data['action']}' aplicada.",
        ]);
    }

    /**
     * PATCH /api/admin/uploaded-works/{work}
     * Edición rápida de campos editoriales.
     */
    public function update(Request $request, Book $work): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['sometimes', 'string', 'max:5000'],
            'slug' => ['sometimes', 'string', 'max:200', 'regex:/^[a-z0-9-]+$/', Rule::unique('books', 'slug')->ignore($work->id)],
            'cover_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'language' => ['sometimes', 'string', 'size:2'],
            'age_rating' => ['sometimes', Rule::in(self::AGE_RATINGS)],
            'tags' => ['sometimes', 'array', 'max:10'],
            'tags.*' => ['string', 'max:30'],
            'category_ids' => ['sometimes', 'array', 'max:5'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'mod_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ], [
            'slug.regex' => 'El slug solo admite letras minúsculas, números y guion.',
            'slug.unique' => 'Ese slug ya está en uso.',
        ]);

        if (isset($data['category_ids'])) {
            $work->categories()->sync($data['category_ids']);
            unset($data['category_ids']);
        }

        $work->update($data);

        return response()->json([
            'data' => $this->serialize($work->fresh(), full: true),
            'message' => 'Cambios guardados.',
        ]);
    }

    /**
     * DELETE /api/admin/uploaded-works/{work}
     * Eliminación (soft delete).
     */
    public function destroy(Request $request, Book $work): JsonResponse
    {
        $work->delete();

        return response()->json(['message' => 'Obra eliminada.']);
    }

    private function serialize(Book $b, bool $full = false): array
    {
        $base = [
            'id' => $b->id,
            'title' => $b->title,
            'slug' => $b->slug,
            'cover' => $b->cover,
            'content_type' => $b->content_type,
            'language' => $b->language,
            'age_rating' => $b->age_rating,
            'submission_status' => $b->submission_status,
            'is_published' => (bool) $b->is_published,
            'is_hidden' => (bool) $b->is_hidden,
            'is_sensitive' => (bool) $b->is_sensitive,
            'is_pinned' => (bool) $b->is_pinned,
            'is_recommended' => (bool) $b->is_recommended,
            'is_trending' => (bool) $b->is_trending,
            'is_featured' => (bool) $b->is_featured,
            'views_count' => (int) $b->views_count,
            'unique_readers_count' => (int) $b->views_count,
            'total_readings_count' => (int) $b->total_readings_count,
            'favorites_count' => (int) $b->favorites_count,
            'rating_average' => (float) $b->rating_average,
            'rating_count' => (int) $b->rating_count,
            'created_at' => $b->created_at?->toIso8601String(),
            'updated_at' => $b->updated_at?->toIso8601String(),
            'creator' => $b->creator ? [
                'id' => $b->creator->id,
                'name' => $b->creator->name,
                'username' => $b->creator->username,
                'avatar' => $b->creator->avatar,
                'creator_level' => $b->creator->creator_level,
            ] : null,
        ];

        if (! $full) return $base;

        return array_merge($base, [
            'description' => $b->description,
            'tags' => $b->tags ?? [],
            'mod_notes' => $b->mod_notes,
            'copyright_holder' => $b->copyright_holder,
            'categories' => $b->categories->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'slug' => $c->slug,
            ])->values(),
            'hidden_at' => $b->hidden_at?->toIso8601String(),
        ]);
    }
}
