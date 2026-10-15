<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserCardResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReaderSearchController extends Controller
{
    /**
     * GET /api/users/search?q=
     * Busca lectores por nombre o username (perfiles públicos).
     */
    public function __invoke(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        if (mb_strlen($term) < 2) {
            return response()->json(['data' => []]);
        }

        $like = '%' . $term . '%';

        $users = User::query()
            ->where('is_public_profile', true)
            ->where(function ($q) use ($like) {
                $q->where('name', 'ILIKE', $like)
                    ->orWhere('username', 'ILIKE', $like);
            })
            ->withCount('followers')
            ->orderByDesc('followers_count')
            ->limit(20)
            ->get();

        return UserCardResource::collection($users)->response();
    }
}
