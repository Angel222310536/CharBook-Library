<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Models\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeedController extends Controller
{
    private const BOOK_COLS = 'id,title,slug,cover_url,cover_path,access_type,language,publication_year,rating_average,rating_count,is_featured,author_id';

    /**
     * GET /api/feed?scope=following|discover
     * Feed de actividad. "following" = gente que sigo + yo; "discover" = global reciente.
     */
    public function index(Request $request): JsonResponse
    {
        $me = $request->user();
        $scope = $request->query('scope', 'following');

        $query = Activity::query()
            ->with([
                'user:id,name,username,avatar',
                'book:' . self::BOOK_COLS,
                'book.author:id,name,slug',
            ])
            ->withExists([
                'likedBy' => fn ($q) => $q->where('users.id', $me->id),
                'sharedBy' => fn ($q) => $q->where('users.id', $me->id),
            ])
            ->latest();

        if ($scope === 'discover') {
            // Global; no mostramos las del propio usuario para que "descubra"
            $query->where('user_id', '!=', $me->id);
        } else {
            $followingIds = $me->following()->pluck('users.id')->push($me->id);
            $query->whereIn('user_id', $followingIds);
        }

        $activities = $query->paginate(20)->withQueryString();

        return ActivityResource::collection($activities)->response();
    }

    /**
     * GET /api/activities/{activity}
     * Una actividad concreta (para enlaces compartidos).
     */
    public function show(Request $request, Activity $activity): JsonResponse
    {
        $me = $request->user('sanctum');

        $activity->load([
            'user:id,name,username,avatar',
            'book:' . self::BOOK_COLS,
            'book.author:id,name,slug',
        ]);

        if ($me) {
            $activity->loadExists([
                'likedBy' => fn ($q) => $q->where('users.id', $me->id),
                'sharedBy' => fn ($q) => $q->where('users.id', $me->id),
            ]);
        }

        return (new ActivityResource($activity))->response();
    }
}
