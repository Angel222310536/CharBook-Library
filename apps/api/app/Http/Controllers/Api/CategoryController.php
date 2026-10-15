<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Category::withCount(['books' => fn($q) => $q->where('is_published', true)])
            ->orderBy('name')
            ->get();
        return CategoryResource::collection($categories)->response();
    }

    public function show(Category $category): JsonResponse
    {
        $category->loadCount(['books' => fn($q) => $q->where('is_published', true)]);
        return (new CategoryResource($category))->response();
    }

    /**
     * GET /api/categories/hierarchical
     * Devuelve las categorías agrupadas: top-level + sus hijos.
     * Usado por el wizard de creación de obras.
     */
    public function hierarchical(): JsonResponse
    {
        $top = Category::query()
            ->whereNull('parent_id')
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        $children = Category::query()
            ->whereNotNull('parent_id')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'parent_id']);

        $byParent = $children->groupBy('parent_id');

        return response()->json([
            'data' => $top->map(fn ($parent) => [
                'id' => $parent->id,
                'name' => $parent->name,
                'slug' => $parent->slug,
                'children' => ($byParent[$parent->id] ?? collect())
                    ->map(fn ($c) => [
                        'id' => $c->id,
                        'name' => $c->name,
                        'slug' => $c->slug,
                    ])
                    ->values(),
            ]),
        ]);
    }

    // ===== Admin =====

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100|unique:categories,name',
            'icon' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:7',
            'description' => 'nullable|string',
        ]);
        return (new CategoryResource(Category::create($data)))->response()->setStatusCode(201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:100|unique:categories,name,' . $category->id,
            'icon' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:7',
            'description' => 'nullable|string',
        ]);
        $category->update($data);
        return (new CategoryResource($category))->response();
    }

    public function destroy(Category $category): JsonResponse
    {
        $category->delete();
        return response()->json(['message' => 'Categoría eliminada.']);
    }
}
