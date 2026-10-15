<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityCommentResource;
use App\Models\Activity;
use App\Models\ActivityComment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ActivityReactionController extends Controller
{
    /**
     * POST /api/activities/{activity}/like  (toggle)
     */
    public function toggleLike(Request $request, Activity $activity): JsonResponse
    {
        $result = $activity->likedBy()->toggle($request->user()->id);
        $liked = ! empty($result['attached']);

        $activity->likes_count = $activity->likedBy()->count();
        $activity->save();

        return response()->json([
            'liked_by_me' => $liked,
            'likes_count' => $activity->likes_count,
        ]);
    }

    /**
     * POST /api/activities/{activity}/share  (toggle)
     */
    public function toggleShare(Request $request, Activity $activity): JsonResponse
    {
        $result = $activity->sharedBy()->toggle($request->user()->id);
        $shared = ! empty($result['attached']);

        $activity->shares_count = $activity->sharedBy()->count();
        $activity->save();

        return response()->json([
            'shared_by_me' => $shared,
            'shares_count' => $activity->shares_count,
        ]);
    }

    /**
     * GET /api/activities/{activity}/comments
     */
    public function comments(Request $request, Activity $activity): JsonResponse
    {
        $comments = $activity->comments()
            ->with('user:id,name,username,avatar')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return ActivityCommentResource::collection($comments)->response();
    }

    /**
     * POST /api/activities/{activity}/comments  { body }
     */
    public function storeComment(Request $request, Activity $activity): JsonResponse
    {
        $data = $request->validate([
            'body' => 'required|string|max:1000',
        ]);

        $comment = $activity->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        $activity->increment('comments_count');
        $comment->load('user:id,name,username,avatar');

        return (new ActivityCommentResource($comment))->response()->setStatusCode(201);
    }

    /**
     * DELETE /api/activities/{activity}/comments/{comment}
     */
    public function destroyComment(Request $request, Activity $activity, ActivityComment $comment): JsonResponse
    {
        if ($comment->activity_id !== $activity->id) {
            abort(404);
        }
        if ($comment->user_id !== $request->user()->id) {
            throw new AccessDeniedHttpException('No es tu comentario.');
        }

        $comment->delete();
        $activity->decrement('comments_count');

        return response()->json(['message' => 'Comentario eliminado.']);
    }
}
