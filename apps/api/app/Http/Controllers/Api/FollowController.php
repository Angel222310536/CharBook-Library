<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserCardResource;
use App\Models\FollowRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FollowController extends Controller
{
    /**
     * POST /api/users/{username}/follow
     *
     * Lógica de 4 estados según privacidad del target:
     *
     *  - Si target tiene perfil PÚBLICO:
     *      ► no estoy siguiendo  → sigo (insert en follows)
     *      ► estoy siguiendo      → unfollow (delete en follows)
     *
     *  - Si target tiene perfil PRIVADO:
     *      ► no estoy siguiendo, no hay request → creo FollowRequest pending
     *      ► hay request pending                → la cancelo
     *      ► estoy siguiendo (request aceptada) → unfollow + marco request canceled
     *
     * Respuesta unificada: { state, is_following, followers_count }
     *   state ∈ "none" | "pending" | "following"
     */
    public function toggle(Request $request, User $user): JsonResponse
    {
        $me = $request->user();

        if ($me->id === $user->id) {
            return response()->json(['message' => 'No puedes seguirte a ti mismo.'], 422);
        }

        $alreadyFollowing = $me->isFollowing($user);

        // CASO 1: ya lo sigo → unfollow (sin importar privacidad)
        if ($alreadyFollowing) {
            $me->following()->detach($user->id);

            FollowRequest::where('requester_id', $me->id)
                ->where('target_id', $user->id)
                ->whereIn('status', [FollowRequest::STATUS_PENDING, FollowRequest::STATUS_ACCEPTED])
                ->update([
                    'status' => FollowRequest::STATUS_CANCELED,
                    'responded_at' => now(),
                ]);

            return response()->json([
                'state' => 'none',
                'is_following' => false,
                'followers_count' => $user->followers()->count(),
            ]);
        }

        // CASO 2: no lo sigo, pero target es PRIVADO → flujo de solicitud
        if (! $user->is_public_profile) {
            $existing = FollowRequest::where('requester_id', $me->id)
                ->where('target_id', $user->id)
                ->first();

            // 2a: ya hay solicitud pendiente → cancelar
            if ($existing && $existing->status === FollowRequest::STATUS_PENDING) {
                $existing->update([
                    'status' => FollowRequest::STATUS_CANCELED,
                    'responded_at' => now(),
                ]);

                return response()->json([
                    'state' => 'none',
                    'is_following' => false,
                    'followers_count' => $user->followers()->count(),
                ]);
            }

            // 2b: no hay pendiente → crear o resucitar request
            if ($existing) {
                $existing->update([
                    'status' => FollowRequest::STATUS_PENDING,
                    'responded_at' => null,
                ]);
            } else {
                FollowRequest::create([
                    'requester_id' => $me->id,
                    'target_id' => $user->id,
                    'status' => FollowRequest::STATUS_PENDING,
                ]);
            }

            return response()->json([
                'state' => 'pending',
                'is_following' => false,
                'followers_count' => $user->followers()->count(),
            ]);
        }

        // CASO 3: target público y no lo sigo → follow inmediato
        $me->following()->attach($user->id);

        return response()->json([
            'state' => 'following',
            'is_following' => true,
            'followers_count' => $user->followers()->count(),
        ]);
    }

    /**
     * GET /api/me/follow-requests
     * Solicitudes de seguimiento entrantes (pending) hacia mí.
     */
    public function incoming(Request $request): JsonResponse
    {
        $me = $request->user();

        $requests = $me->incomingFollowRequests()
            ->with('requester:id,name,username,avatar,bio')
            ->where('status', FollowRequest::STATUS_PENDING)
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return response()->json([
            'data' => $requests->getCollection()->map(function (FollowRequest $r) {
                return [
                    'id' => $r->id,
                    'requester' => [
                        'id' => $r->requester->id,
                        'name' => $r->requester->name,
                        'username' => $r->requester->username,
                        'avatar' => $r->requester->avatar,
                        'bio' => $r->requester->bio,
                    ],
                    'requested_at' => $r->created_at?->toIso8601String(),
                ];
            }),
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    /**
     * GET /api/me/follow-requests/count
     * Solo el conteo (para badge en el header).
     */
    public function incomingCount(Request $request): JsonResponse
    {
        $count = $request->user()
            ->incomingFollowRequests()
            ->where('status', FollowRequest::STATUS_PENDING)
            ->count();

        return response()->json(['count' => $count]);
    }

    /**
     * POST /api/me/follow-requests/{followRequest}/accept
     * Acepta: crea el follow real y marca como accepted.
     */
    public function accept(Request $request, FollowRequest $followRequest): JsonResponse
    {
        $me = $request->user();

        if ($followRequest->target_id !== $me->id) {
            return response()->json(['message' => 'Esta solicitud no es para ti.'], 403);
        }

        if ($followRequest->status !== FollowRequest::STATUS_PENDING) {
            return response()->json(['message' => 'Esta solicitud ya fue respondida.'], 422);
        }

        $me->followers()->syncWithoutDetaching([$followRequest->requester_id]);

        $followRequest->update([
            'status' => FollowRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);

        return response()->json([
            'message' => 'Solicitud aceptada.',
            'request_id' => $followRequest->id,
        ]);
    }

    /**
     * POST /api/me/follow-requests/{followRequest}/reject
     * Rechaza sin crear el follow.
     */
    public function reject(Request $request, FollowRequest $followRequest): JsonResponse
    {
        $me = $request->user();

        if ($followRequest->target_id !== $me->id) {
            return response()->json(['message' => 'Esta solicitud no es para ti.'], 403);
        }

        if ($followRequest->status !== FollowRequest::STATUS_PENDING) {
            return response()->json(['message' => 'Esta solicitud ya fue respondida.'], 422);
        }

        $followRequest->update([
            'status' => FollowRequest::STATUS_REJECTED,
            'responded_at' => now(),
        ]);

        return response()->json([
            'message' => 'Solicitud rechazada.',
            'request_id' => $followRequest->id,
        ]);
    }

    /**
     * GET /api/users/{username}/followers
     */
    public function followers(Request $request, User $user): JsonResponse
    {
        $followers = $user->followers()
            ->orderByDesc('follows.created_at')
            ->paginate(24)
            ->withQueryString();

        return UserCardResource::collection($followers)->response();
    }

    /**
     * GET /api/users/{username}/following
     */
    public function following(Request $request, User $user): JsonResponse
    {
        $following = $user->following()
            ->orderByDesc('follows.created_at')
            ->paginate(24)
            ->withQueryString();

        return UserCardResource::collection($following)->response();
    }
}
