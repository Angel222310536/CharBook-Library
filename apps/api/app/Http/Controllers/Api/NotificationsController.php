<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationsController extends Controller
{
    /**
     * GET /api/me/notifications
     * Lista paginada con filtro opcional por categoría.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $category = $request->query('category');
        $unreadOnly = $request->boolean('unread_only', false);

        $query = $user->notifications()->latest();

        if ($category && $category !== 'all') {
            $query->where('category', $category);
        }
        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        $results = $query->paginate(30)->withQueryString();

        return response()->json([
            'data' => $results->getCollection()->map(fn ($n) => $this->serialize($n)),
            'meta' => [
                'current_page' => $results->currentPage(),
                'last_page' => $results->lastPage(),
                'total' => $results->total(),
                'unread_total' => $user->notifications()->whereNull('read_at')->count(),
            ],
        ]);
    }

    /** GET /api/me/notifications/unread-count */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = $request->user()->notifications()->whereNull('read_at')->count();
        return response()->json(['count' => $count]);
    }

    /**
     * GET /api/me/notifications/activity
     * Centro de actividad: resumen agrupado por Hoy / Esta semana.
     */
    public function activity(Request $request): JsonResponse
    {
        $user = $request->user();
        $now = now();

        $today = $user->notifications()
            ->whereDate('created_at', $now->toDateString())
            ->get(['id', 'category', 'type', 'emoji', 'title', 'created_at']);

        $week = $user->notifications()
            ->where('created_at', '>=', $now->copy()->startOfWeek())
            ->where('created_at', '<', $now->toDateString() . ' 00:00:00')
            ->get(['id', 'category', 'type', 'emoji', 'title', 'created_at']);

        return response()->json([
            'today' => $this->groupSummary($today),
            'this_week' => $this->groupSummary($week),
        ]);
    }

    /** PATCH /api/me/notifications/{n}/read */
    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        $this->assertOwnership($notification, $request->user());
        if (! $notification->read_at) {
            $notification->update(['read_at' => now()]);
        }
        return response()->json(['data' => $this->serialize($notification->fresh())]);
    }

    /** POST /api/me/notifications/read-all */
    public function readAll(Request $request): JsonResponse
    {
        $request->user()->notifications()->whereNull('read_at')->update(['read_at' => now()]);
        return response()->json(['message' => 'Todas marcadas como leídas.']);
    }

    /** DELETE /api/me/notifications/{n} */
    public function destroy(Request $request, Notification $notification): JsonResponse
    {
        $this->assertOwnership($notification, $request->user());
        $notification->delete();
        return response()->json(['message' => 'Notificación eliminada.']);
    }

    private function assertOwnership(Notification $n, $user): void
    {
        if ($n->user_id !== $user->id) abort(403);
    }

    private function serialize(Notification $n): array
    {
        return [
            'id' => $n->id,
            'category' => $n->category,
            'type' => $n->type,
            'emoji' => $n->emoji,
            'title' => $n->title,
            'body' => $n->body,
            'link' => $n->link,
            'data' => $n->data,
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }

    private function groupSummary($collection): array
    {
        $byCategory = $collection->groupBy('category');
        return $byCategory->map(fn ($group, $cat) => [
            'category' => $cat,
            'count' => $group->count(),
            'preview' => $group->take(3)->map(fn ($n) => [
                'id' => $n->id,
                'emoji' => $n->emoji,
                'title' => $n->title,
                'created_at' => $n->created_at?->toIso8601String(),
            ])->values(),
        ])->values()->all();
    }
}
