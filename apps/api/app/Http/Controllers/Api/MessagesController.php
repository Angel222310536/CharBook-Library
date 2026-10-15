<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessagesController extends Controller
{
    /**
     * GET /api/me/messages
     * Bandeja con filtros: category, view (inbox|important|archived), search.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $category = $request->query('category');
        $view = $request->query('view', 'inbox');
        $search = trim((string) $request->query('search', ''));

        $query = $user->messages()->latest();

        if ($category && $category !== 'all') {
            $query->where('category', $category);
        }

        if ($view === 'archived') {
            $query->whereNotNull('archived_at');
        } else {
            $query->whereNull('archived_at');
        }
        if ($view === 'important') {
            $query->whereNotNull('important_at');
        }
        if ($view === 'unread') {
            $query->whereNull('read_at');
        }

        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $query->where(function ($q) use ($like) {
                $q->where('subject', 'ILIKE', $like)
                    ->orWhere('body', 'ILIKE', $like);
            });
        }

        $results = $query->paginate(20)->withQueryString();

        return response()->json([
            'data' => $results->getCollection()->map(fn ($m) => $this->serialize($m)),
            'meta' => [
                'current_page' => $results->currentPage(),
                'last_page' => $results->lastPage(),
                'total' => $results->total(),
                'unread_total' => $user->messages()->whereNull('read_at')->whereNull('archived_at')->count(),
                'important_total' => $user->messages()->whereNotNull('important_at')->whereNull('archived_at')->count(),
            ],
        ]);
    }

    /** GET /api/me/messages/counts (por categoría + globales) */
    public function counts(Request $request): JsonResponse
    {
        $user = $request->user();
        $base = $user->messages()->whereNull('archived_at');

        return response()->json([
            'all' => (clone $base)->count(),
            'unread' => (clone $base)->whereNull('read_at')->count(),
            'important' => $user->messages()->whereNotNull('important_at')->whereNull('archived_at')->count(),
            'archived' => $user->messages()->whereNotNull('archived_at')->count(),
            'by_category' => collect(Message::CATEGORIES)->mapWithKeys(fn ($c) => [
                $c => (clone $base)->where('category', $c)->count(),
            ]),
        ]);
    }

    /** GET /api/me/messages/{message} */
    public function show(Request $request, Message $message): JsonResponse
    {
        $this->assertOwnership($message, $request->user());
        // Marcar como leído al abrir
        if (! $message->read_at) {
            $message->update(['read_at' => now()]);
        }
        return response()->json(['data' => $this->serialize($message->fresh(), full: true)]);
    }

    /** PATCH /api/me/messages/{message} con action: read|unread|important|unimportant|archive|unarchive */
    public function update(Request $request, Message $message): JsonResponse
    {
        $this->assertOwnership($message, $request->user());
        $data = $request->validate(['action' => 'required|in:read,unread,important,unimportant,archive,unarchive']);
        $now = now();

        match ($data['action']) {
            'read' => $message->update(['read_at' => $now]),
            'unread' => $message->update(['read_at' => null]),
            'important' => $message->update(['important_at' => $now, 'read_at' => $message->read_at ?? $now]),
            'unimportant' => $message->update(['important_at' => null]),
            'archive' => $message->update(['archived_at' => $now, 'read_at' => $message->read_at ?? $now]),
            'unarchive' => $message->update(['archived_at' => null]),
        };

        return response()->json(['data' => $this->serialize($message->fresh())]);
    }

    /** POST /api/me/messages/read-all — marca todos los no leídos como leídos */
    public function readAll(Request $request): JsonResponse
    {
        $user = $request->user();
        $updated = $user->messages()
            ->whereNull('read_at')
            ->whereNull('archived_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => "{$updated} mensaje(s) marcados como leídos.",
            'updated' => $updated,
        ]);
    }

    /** DELETE /api/me/messages/{message} */
    public function destroy(Request $request, Message $message): JsonResponse
    {
        $this->assertOwnership($message, $request->user());
        $message->delete();
        return response()->json(['message' => 'Mensaje eliminado.']);
    }

    private function assertOwnership(Message $m, $user): void
    {
        if ($m->user_id !== $user->id) abort(403);
    }

    private function serialize(Message $m, bool $full = false): array
    {
        $base = [
            'id' => $m->id,
            'category' => $m->category,
            'priority' => $m->priority,
            'subject' => $m->subject,
            'preview' => mb_substr(strip_tags($m->body), 0, 140),
            'sender_name' => $m->sender_name,
            'sender_role' => $m->sender_role,
            'is_read' => (bool) $m->read_at,
            'is_important' => (bool) $m->important_at,
            'is_archived' => (bool) $m->archived_at,
            'created_at' => $m->created_at?->toIso8601String(),
        ];

        if ($full) {
            $base['body'] = $m->body;
            $base['attachments'] = $m->attachments ?? [];
            $base['actions'] = $m->actions ?? [];
            $base['read_at'] = $m->read_at?->toIso8601String();
            $base['important_at'] = $m->important_at?->toIso8601String();
            $base['archived_at'] = $m->archived_at?->toIso8601String();
        }

        return $base;
    }
}
