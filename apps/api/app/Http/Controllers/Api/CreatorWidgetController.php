<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CreatorWidget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CreatorWidgetController extends Controller
{
    /**
     * GET /api/me/widgets
     * Mis widgets (creador autenticado).
     */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertCreator($user);

        $widgets = $user->creatorWidgets()
            ->with(['book:id,title,slug,cover_url,cover_path', 'category:id,name,slug'])
            ->orderBy('position')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $widgets]);
    }

    /**
     * POST /api/me/widgets
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertCreator($user);

        $data = $request->validate([
            'type' => ['required', Rule::in(CreatorWidget::TYPES)],
            'title' => ['required', 'string', 'max:140'],
            'body' => ['nullable', 'string', 'max:4000'],
            'url' => ['nullable', 'url', 'max:500'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'book_id' => ['nullable', 'integer', 'exists:books,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'is_published' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);

        $widget = $user->creatorWidgets()->create($data);

        return response()->json(['data' => $widget->fresh()], 201);
    }

    /**
     * PATCH /api/me/widgets/{widget}
     */
    public function update(Request $request, CreatorWidget $widget): JsonResponse
    {
        $user = $request->user();
        $this->assertCreator($user);
        $this->assertOwner($user, $widget);

        $data = $request->validate([
            'type' => ['sometimes', Rule::in(CreatorWidget::TYPES)],
            'title' => ['sometimes', 'string', 'max:140'],
            'body' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],
            'book_id' => ['sometimes', 'nullable', 'integer', 'exists:books,id'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
            'is_published' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);

        $widget->update($data);

        return response()->json(['data' => $widget->fresh()]);
    }

    /**
     * DELETE /api/me/widgets/{widget}
     */
    public function destroy(Request $request, CreatorWidget $widget): JsonResponse
    {
        $user = $request->user();
        $this->assertCreator($user);
        $this->assertOwner($user, $widget);

        $widget->delete();

        return response()->json(['message' => 'Widget eliminado.']);
    }

    private function assertCreator($user): void
    {
        // Modelo abierto: cualquier user autenticado puede tener widgets en su Atelier.
        // La verificación (badge ✓) es opcional y no condiciona el feature.
        if (! $user) {
            abort(401, 'Debes iniciar sesión para gestionar tu Atelier.');
        }
    }

    private function assertOwner($user, CreatorWidget $widget): void
    {
        if ($widget->user_id !== $user->id) {
            abort(403, 'Este widget no es tuyo.');
        }
    }
}
