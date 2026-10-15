<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Difusión masiva desde el panel admin.
 *   - Notificación: enviada a TODOS los users (rápida, centro de notificaciones)
 *   - Mensaje:      enviado a TODOS los users (extenso, bandeja de comunicaciones)
 */
class AdminBroadcastController extends Controller
{
    /**
     * POST /api/admin/broadcast/notification
     */
    public function notification(Request $request): JsonResponse
    {
        $data = $request->validate([
            'emoji' => 'nullable|string|max:8',
            'title' => 'required|string|max:200',
            'body' => 'nullable|string|max:500',
            'link' => 'nullable|string|max:500',
            'category' => 'sometimes|in:system,reading,comments,followers,works,monetization',
            'target' => 'sometimes|in:all,creators,verified,featured,user',
            'user_id' => 'required_if:target,user|integer|exists:users,id',
        ]);

        $target = $data['target'] ?? 'all';
        $userIds = $this->resolveTarget($target, $data['user_id'] ?? null);

        $now = now();
        $rows = $userIds->map(fn ($uid) => [
            'user_id' => $uid,
            'category' => $data['category'] ?? 'system',
            'type' => 'broadcast_admin',
            'emoji' => $data['emoji'] ?? '📢',
            'title' => $data['title'],
            'body' => $data['body'] ?? null,
            'link' => $data['link'] ?? null,
            'data' => null,
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        // insert en chunks por si hay muchos usuarios
        foreach (array_chunk($rows, 500) as $chunk) {
            Notification::insert($chunk);
        }

        return response()->json([
            'message' => "Notificación enviada a {$userIds->count()} usuario(s).",
            'sent_count' => $userIds->count(),
        ], 201);
    }

    /**
     * POST /api/admin/broadcast/message
     */
    public function message(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => 'required|in:platform,event,tip,achievement,report,review',
            'priority' => 'sometimes|in:low,normal,high,urgent',
            'subject' => 'required|string|max:200',
            'body' => 'required|string',
            'sender_name' => 'sometimes|string|max:100',
            'target' => 'sometimes|in:all,creators,verified,featured,user',
            'user_id' => 'required_if:target,user|integer|exists:users,id',
        ]);

        $target = $data['target'] ?? 'all';
        $userIds = $this->resolveTarget($target, $data['user_id'] ?? null);
        $sender = $data['sender_name'] ?? 'Equipo CharBook';

        $now = now();
        $rows = $userIds->map(fn ($uid) => [
            'user_id' => $uid,
            'category' => $data['category'],
            'priority' => $data['priority'] ?? 'normal',
            'subject' => $data['subject'],
            'body' => $data['body'],
            'sender_name' => $sender,
            'sender_role' => 'admin',
            'attachments' => null,
            'actions' => null,
            'read_at' => null,
            'important_at' => null,
            'archived_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            Message::insert($chunk);
        }

        return response()->json([
            'message' => "Mensaje enviado a {$userIds->count()} usuario(s).",
            'sent_count' => $userIds->count(),
        ], 201);
    }

    /**
     * POST /api/admin/messages/to-user
     * Enviar un mensaje individual (ej. revisión de obra).
     */
    public function messageToUser(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'category' => 'required|in:review,report,achievement,event,tip,platform',
            'priority' => 'sometimes|in:low,normal,high,urgent',
            'subject' => 'required|string|max:200',
            'body' => 'required|string',
        ]);

        $message = Message::create([
            'user_id' => $data['user_id'],
            'category' => $data['category'],
            'priority' => $data['priority'] ?? 'normal',
            'subject' => $data['subject'],
            'body' => $data['body'],
            'sender_name' => 'Equipo CharBook',
            'sender_role' => 'staff',
        ]);

        return response()->json([
            'data' => $message,
            'message' => 'Mensaje enviado.',
        ], 201);
    }

    private function resolveTarget(string $target, ?int $singleUserId = null)
    {
        return match ($target) {
            'user' => collect($singleUserId ? [$singleUserId] : []),
            'creators' => User::query()
                ->whereHas('booksAsCreator', fn ($q) => $q->where('is_published', true))
                ->pluck('id'),
            'verified' => User::query()
                ->where('creator_status', 'approved')
                ->whereHas('roles', fn ($q) => $q->where('name', 'creator'))
                ->pluck('id'),
            'featured' => User::query()
                ->where('creator_tier', 'featured')
                ->pluck('id'),
            default => User::query()->pluck('id'),
        };
    }

    /**
     * GET /api/admin/users/search?q=
     * Para el picker de usuario en el broadcast.
     */
    public function searchUsers(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['data' => []]);
        }

        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $users = User::query()
            ->where(function ($w) use ($like) {
                $w->where('name', 'ILIKE', $like)
                    ->orWhere('username', 'ILIKE', $like)
                    ->orWhere('email', 'ILIKE', $like);
            })
            ->limit(12)
            ->get(['id', 'name', 'username', 'email', 'avatar']);

        return response()->json([
            'data' => $users->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'username' => $u->username,
                'email' => $u->email,
                'avatar' => $u->avatar,
            ]),
        ]);
    }
}
