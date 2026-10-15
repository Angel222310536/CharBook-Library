<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookListResource;
use App\Models\Activity;
use App\Models\Book;
use App\Models\Favorite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    /**
     * POST /api/books/{slug}/favorite
     * Alterna favorito del usuario actual. Devuelve { is_favorited, count }.
     */
    public function toggle(Request $request, Book $book): JsonResponse
    {
        $user = $request->user();

        $favorite = Favorite::where('user_id', $user->id)
            ->where('book_id', $book->id)
            ->first();

        if ($favorite) {
            $favorite->delete();
            $book->decrement('favorites_count');
            $isFav = false;
        } else {
            Favorite::create([
                'user_id' => $user->id,
                'book_id' => $book->id,
            ]);
            $book->increment('favorites_count');
            $isFav = true;

            // Actividad para el feed de la comunidad
            Activity::log($user->id, 'favorited', $book->id);
        }

        return response()->json([
            'is_favorited' => $isFav,
            'favorites_count' => $book->fresh()->favorites_count,
        ]);
    }

    /**
     * GET /api/me/favorites
     * Mis favoritos paginados.
     */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();

        $books = $user->favoriteBooks()
            ->with('author:id,name,slug')
            ->where('is_published', true)
            ->orderByPivot('created_at', 'desc')
            ->paginate(24)
            ->withQueryString();

        return BookListResource::collection($books)->response();
    }
}
