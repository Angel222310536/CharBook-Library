<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\BookMetadataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BookLookupController extends Controller
{
    public function __construct(protected BookMetadataService $metadata) {}

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'isbn' => 'nullable|string|max:20',
            'title' => 'nullable|string|max:255',
            'author' => 'nullable|string|max:150',
        ]);

        $isbn = $request->input('isbn');
        $title = $request->input('title');
        $author = $request->input('author');

        if (! $isbn && ! $title) {
            return response()->json(['message' => 'Debes enviar ISBN o título.'], 422);
        }

        try {
            $result = $isbn
                ? $this->metadata->lookupByIsbn($isbn)
                : $this->metadata->lookupByTitle($title, $author);
        } catch (\Throwable $e) {
            Log::error('BookLookup exception: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'message' => 'Error interno al consultar metadata.',
                'detail' => app()->isLocal() ? $e->getMessage() : null,
            ], 500);
        }

        if (! $result) {
            return response()->json([
                'message' => 'No se encontró metadata. Verifica el ISBN o intenta con otro término.',
            ], 404);
        }

        return response()->json(['data' => $result]);
    }
}
