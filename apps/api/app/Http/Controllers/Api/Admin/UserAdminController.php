<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PlatformSettings;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class UserAdminController extends Controller
{
    /** Roles asignables (excluye super_admin si no eres super). */
    private function assignableRoles(User $actor): array
    {
        if ($actor->hasRole('super_admin')) {
            return ['super_admin', 'admin', 'moderator', 'creator', 'reader'];
        }

        return ['moderator', 'creator', 'reader'];
    }

    /**
     * GET /api/admin/users
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query()
            ->withCount(['reviews', 'favorites'])
            ->with('roles:id,name');

        if ($search = $request->input('search')) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $query->where(function ($w) use ($like) {
                $w->where('name', 'ILIKE', $like)
                  ->orWhere('email', 'ILIKE', $like)
                  ->orWhere('username', 'ILIKE', $like);
            });
        }

        if ($role = $request->input('role')) {
            $query->whereHas('roles', fn($q) => $q->where('name', $role));
        }

        $query->latest('created_at');

        $users = $query->paginate(25)->withQueryString();

        return response()->json([
            'data' => $users->map(fn(User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'username' => $u->username,
                'email' => $u->email,
                'avatar' => $u->avatar,
                'created_at' => $u->created_at?->toIso8601String(),
                'locale' => $u->locale ?? 'es',
                'trust_score' => $u->trust_score ?? 25,
                'creator_status' => $u->creator_status ?? 'none',
                'creator_tier' => $u->creator_tier ?? 'none',
                'creator_level' => $u->creator_level,
                'subscription_tier' => $u->subscription_tier ?? 'free',
                'roles' => $u->roles->pluck('name'),
                'reviews_count' => $u->reviews_count ?? 0,
                'favorites_count' => $u->favorites_count ?? 0,
            ]),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    /**
     * GET /api/admin/users/{user}
     */
    public function show(User $user): JsonResponse
    {
        $user->load('roles:id,name');
        $user->loadCount(['reviews', 'favorites', 'followers', 'following', 'anthologies']);

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'avatar' => $user->avatar,
                'bio' => $user->bio,
                'is_public_profile' => $user->is_public_profile,
                'locale' => $user->locale ?? 'es',
                'trust_score' => $user->trust_score ?? 25,
                'creator_status' => $user->creator_status ?? 'none',
                'creator_tier' => $user->creator_tier ?? 'none',
                'creator_level' => $user->creator_level,
                'subscription_tier' => $user->subscription_tier ?? 'free',
                'roles' => $user->roles->pluck('name'),
                'created_at' => $user->created_at?->toIso8601String(),
                'stats' => [
                    'reviews' => $user->reviews_count,
                    'favorites' => $user->favorites_count,
                    'followers' => $user->followers_count,
                    'following' => $user->following_count,
                    'anthologies' => $user->anthologies_count,
                ],
            ],
        ]);
    }

    /**
     * PATCH /api/admin/users/{user}/roles
     * Asigna o quita un rol. Body: { role: 'admin', action: 'assign'|'remove' }
     */
    public function updateRoles(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        $data = $request->validate([
            'role' => 'required|string',
            'action' => 'required|in:assign,remove',
        ]);

        $assignable = $this->assignableRoles($actor);
        if (! in_array($data['role'], $assignable, true)) {
            return response()->json([
                'message' => 'No puedes asignar/quitar ese rol.',
            ], 403);
        }

        // Solo super_admin puede tocar a otro super_admin
        if ($user->hasRole('super_admin') && ! $actor->hasRole('super_admin')) {
            return response()->json(['message' => 'No puedes modificar a un super admin.'], 403);
        }

        // Nadie se puede degradar a sí mismo del último super_admin
        if ($data['action'] === 'remove'
            && $data['role'] === 'super_admin'
            && $user->id === $actor->id) {
            return response()->json([
                'message' => 'No puedes quitarte el rol super_admin a ti mismo.',
            ], 422);
        }

        if ($data['action'] === 'assign') {
            $user->assignRole($data['role']);

            // Si asignamos rol "creator" manualmente desde el panel, también
            // marcamos su creator_status como "approved" para que pase los
            // checks de la app (is_creator, gates al Studio, etc.).
            if ($data['role'] === 'creator' && $user->creator_status !== 'approved') {
                $user->forceFill(['creator_status' => 'approved'])->save();
            }
        } else {
            $user->removeRole($data['role']);

            // Espejo simétrico: quitar el rol creator regresa el status a "none"
            // para que la UI no quede en un estado intermedio confuso.
            if ($data['role'] === 'creator') {
                $user->forceFill(['creator_status' => 'none'])->save();
            }
        }

        $user->load('roles:id,name');

        return response()->json([
            'roles' => $user->roles->pluck('name'),
            'message' => $data['action'] === 'assign'
                ? "Rol '{$data['role']}' asignado."
                : "Rol '{$data['role']}' quitado.",
        ]);
    }

    /**
     * PATCH /api/admin/users/{user}/creator-tier
     * Body: { level: 'none'|'basic'|'verified'|'featured' }  (preferido)
     *    o  { tier:  'none'|'featured' }                      (legacy, compat)
     *
     * `creator_level` es la FUENTE DE VERDAD del reparto de ingresos. El badge
     * visual `creator_tier` ('featured'|'none') se DERIVA del nivel. Así, lo que
     * administra el panel coincide exactamente con el % que aplica el sistema.
     */
    public function updateCreatorTier(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'level' => 'required_without:tier|in:none,basic,verified,featured',
            'tier' => 'required_without:level|in:none,featured',
        ]);

        // Legacy 'tier' → nivel económico equivalente.
        $level = $data['level'] ?? ($data['tier'] === 'featured' ? 'featured' : 'none');

        // El badge dorado se deriva del nivel; el reparto lo lee de creator_level.
        $user->forceFill([
            'creator_level' => $level,
            'creator_tier' => $level === 'featured' ? 'featured' : 'none',
        ]);
        if ($level !== 'none') {
            $user->forceFill(['creator_status' => 'approved']);
        }
        $user->save();

        if ($level !== 'none' && ! $user->hasRole('creator')) {
            $user->assignRole('creator');
        }

        // % real según los settings vigentes (no hardcodeado).
        $pct = PlatformSettings::creatorSharePct($user->creator_level);

        return response()->json([
            'creator_level' => $user->creator_level,
            'creator_tier' => $user->creator_tier,
            'creator_status' => $user->creator_status,
            'revenue_share_pct' => $pct,
            'message' => "Nivel económico actualizado a {$level} ({$pct}% para el creador).",
        ]);
    }

    public function updateMembership(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        if (! $actor->hasRole('super_admin')) {
            return response()->json(['message' => 'Solo super admin puede otorgar membresias.'], 403);
        }

        $data = $request->validate([
            'tier' => 'required|in:free,premium,premium_plus',
        ]);

        $user->forceFill([
            'subscription_tier' => $data['tier'],
            'subscription_ends_at' => null,
        ])->save();

        $label = match ($data['tier']) {
            'premium_plus' => 'Premium Plus',
            'premium' => 'Premium',
            default => 'Gratis',
        };

        return response()->json([
            'subscription_tier' => $user->subscription_tier,
            'message' => "Membresia actualizada a {$label}.",
        ]);
    }

    public function grantCoins(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        if (! $actor->hasRole('super_admin')) {
            return response()->json(['message' => 'Solo super admin puede enviar CharCoins.'], 403);
        }

        $data = $request->validate([
            'currency' => 'required|in:premium',
            'amount' => 'required|integer|min:1|max:100000',
            'reason' => 'nullable|string|max:240',
        ]);

        $amount = (int) $data['amount'];
        $currency = $data['currency'];

        $transaction = app(WalletService::class)->move(
            $user->id,
            $currency,
            $amount,
            'admin_adjust',
            'admin-grant:' . $actor->id . ':' . $user->id . ':' . Str::uuid(),
            [
                'admin_id' => $actor->id,
                'reason' => $data['reason'] ?? null,
                'source' => 'admin_users_panel',
            ],
        );

        return response()->json([
            'transaction_id' => $transaction->id,
            'currency' => $currency,
            'amount' => $amount,
            'balance_after' => $transaction->balance_after,
            'message' => "{$amount} CharCoins Premium enviados a {$user->name}.",
        ]);
    }
}
