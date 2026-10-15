<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Models\Activity;
use App\Models\Book;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    /**
     * GET /api/books/{slug}/reviews
     * Reviews del libro paginadas (públicas).
     */
    public function index(Request $request, Book $book): JsonResponse
    {
        $reviews = $book->reviews()
            ->with('user:id,name,avatar')
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return ReviewResource::collection($reviews)->response();
    }

    /**
     * POST /api/books/{slug}/reviews
     * Crea o actualiza MI review para este libro (upsert).
     */
    public function upsert(Request $request, Book $book): JsonResponse
    {
        $data = $request->validate([
            'rating' => 'required|integer|between:1,5',
            'title' => 'nullable|string|max:200',
            'comment' => 'nullable|string|max:5000',
        ]);

        $user = $request->user();

        $review = Review::updateOrCreate(
            ['user_id' => $user->id, 'book_id' => $book->id],
            $data
        );

        $review->load('user:id,name,avatar');

        // Actividad para el feed de la comunidad
        Activity::log($user->id, 'reviewed', $book->id, ['rating' => $review->rating]);

        return (new ReviewResource($review))->response()->setStatusCode(
            $review->wasRecentlyCreated ? 201 : 200
        );
    }

    /**
     * DELETE /api/books/{slug}/reviews/me
     * Borra MI review para este libro.
     */
    public function destroyMine(Request $request, Book $book): JsonResponse
    {
        $deleted = Review::where('user_id', $request->user()->id)
            ->where('book_id', $book->id)
            ->delete();

        if (! $deleted) {
            return response()->json(['message' => 'No tienes review para este libro.'], 404);
        }

        return response()->json(['message' => 'Review eliminada.']);
    }

    /**
     * DELETE /api/admin/reviews/{review}
     * Staff (admin/super_admin/moderator) elimina cualquier reseña.
     * Útil para moderar contenido reportado.
     */
    public function adminDestroy(Request $request, Review $review): JsonResponse
    {
        $bookId = $review->book_id;
        $review->delete();

        // Recalcular rating del libro tras eliminar (Review::booted ya lo hace en deleted)
        return response()->json([
            'message' => 'Reseña eliminada por staff.',
            'book_id' => $bookId,
        ]);
    }

    /**
     * GET /api/me/reviews
     * Mis reviews (lista de libros que he reseñado).
     */
    public function mine(Request $request): JsonResponse
    {
        $reviews = Review::where('user_id', $request->user()->id)
            ->with(['book:id,title,slug,cover_url,cover_path', 'book.author:id,name,slug'])
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return ReviewResource::collection($reviews)->additional([
            'meta' => ['note' => 'cada review incluye book embebido'],
        ])->response();
    }
}
