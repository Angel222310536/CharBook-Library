<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookListResource;
use App\Models\Activity;
use App\Models\Book;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReadingStatusController extends Controller
{
    private const STATUSES = ['want_to_read', 'reading', 'read'];

    /**
     * PUT /api/books/{slug}/status  { status }
     * Coloca el libro en un estante de lectura del usuario.
     */
    public function set(Request $request, Book $book): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:' . implode(',', self::STATUSES),
        ]);

        $user = $request->user();
        $status = $data['status'];

        $pivot = ['status' => $status];
        if ($status === 'reading') {
            $pivot['started_at'] = now();
        } elseif ($status === 'read') {
            $pivot['finished_at'] = now();
        }

        if ($user->shelf()->where('book_id', $book->id)->exists()) {
            $user->shelf()->updateExistingPivot($book->id, $pivot);
        } else {
            $user->shelf()->attach($book->id, $pivot);
        }

        // Actividad para el feed
        if ($status === 'reading') {
            Activity::log($user->id, 'started_reading', $book->id);
        } elseif ($status === 'read') {
            Activity::log($user->id, 'finished_reading', $book->id);
        } elseif ($status === 'want_to_read') {
            Activity::log($user->id, 'want_to_read', $book->id);
        }

        return response()->json(['status' => $status]);
    }

    /**
     * DELETE /api/books/{slug}/status
     * Quita el libro de los estantes.
     */
    public function remove(Request $request, Book $book): JsonResponse
    {
        $request->user()->shelf()->detach($book->id);

        return response()->json(['status' => null]);
    }

    /**
     * GET /api/me/shelf?status=reading
     * Mis libros por estado de lectura.
     */
    public function mine(Request $request): JsonResponse
    {
        $request->validate([
            'status' => 'nullable|in:' . implode(',', self::STATUSES),
        ]);

        // OJO: dentro de when() el closure recibe el Builder (no la relación),
        // por lo que wherePivot() caería en el dynamic-where y generaría
        // la columna inexistente "pivot". Calificamos la columna a mano.
        $books = $request->user()->shelf()
            ->when($request->filled('status'), fn ($q) =>
                $q->where('book_user.status', $request->query('status'))
            )
            ->with('author:id,name,slug')
            ->where('is_published', true)
            ->orderByPivot('updated_at', 'desc')
            ->paginate(24)
            ->withQueryString();

        return BookListResource::collection($books)->response();
    }
}
