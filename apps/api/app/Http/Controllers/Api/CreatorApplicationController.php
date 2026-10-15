<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CreatorApplicationResource;
use App\Models\CreatorApplication;
use App\Services\CreatorApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreatorApplicationController extends Controller
{
    public function __construct(protected CreatorApplicationService $service) {}

    /**
     * GET /api/me/can-apply-creator
     * Devuelve checklist + cooldown si aplica.
     */
    public function canApply(Request $request): JsonResponse
    {
        $user = $request->user();
        $prereqs = $this->service->checkPrerequisites($user);
        $cooldown = $this->service->getCooldownRemainingDays($user);
        $hasActive = $this->service->hasActiveApplication($user);

        return response()->json([
            'can_apply' => $prereqs['can_apply'] && ! $cooldown && ! $hasActive,
            'checks' => $prereqs['checks'],
            'cooldown_days_remaining' => $cooldown,
            'has_active_application' => $hasActive,
            'creator_status' => $user->creator_status,
        ]);
    }

    /**
     * GET /api/me/creator-application
     * La solicitud actual (activa o más reciente).
     */
    public function mine(Request $request): JsonResponse
    {
        $app = $request->user()
            ->creatorApplications()
            ->latest('submitted_at')
            ->first();

        if (! $app) {
            return response()->json(['data' => null]);
        }

        return (new CreatorApplicationResource($app))->response();
    }

    /**
     * POST /api/me/creator-application
     * Crear una solicitud nueva.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        // Validaciones de negocio
        $prereqs = $this->service->checkPrerequisites($user);
        if (! $prereqs['can_apply']) {
            return response()->json([
                'message' => 'No cumples los requisitos previos.',
                'checks' => $prereqs['checks'],
            ], 422);
        }
        if ($this->service->hasActiveApplication($user)) {
            return response()->json([
                'message' => 'Ya tienes una solicitud activa.',
            ], 409);
        }
        $cooldown = $this->service->getCooldownRemainingDays($user);
        if ($cooldown) {
            return response()->json([
                'message' => "Debes esperar {$cooldown} día(s) tras tu último rechazo.",
            ], 429);
        }

        $data = $request->validate([
            'display_name' => 'required|string|max:100',
            'pen_name' => 'nullable|string|max:100',
            'bio_long' => 'required|string|min:500|max:2000',
            'sample_url' => 'required|url|max:500',
            'genres' => 'required|array|min:1|max:5',
            'genres.*' => 'string|max:30',
            'languages' => 'required|array|min:1|max:5',
            'languages.*' => 'string|size:2',
            'why_join' => 'required|string|min:250|max:1000',
            'social_links' => 'nullable|array',
            'social_links.*' => 'url|max:500',
            'publish_frequency' => 'nullable|in:weekly,biweekly,monthly,irregular',
            'publish_plan' => 'nullable|string|max:1000',
        ]);

        $app = CreatorApplication::create([
            'user_id' => $user->id,
            'status' => CreatorApplication::STATUS_PENDING,
            'submitted_at' => now(),
            ...$data,
        ]);

        $user->update(['creator_status' => 'pending']);

        return (new CreatorApplicationResource($app))->response()->setStatusCode(201);
    }

    /**
     * PATCH /api/me/creator-application
     * Actualizar una solicitud en estado changes_requested.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $app = $user->creatorApplications()
            ->where('status', CreatorApplication::STATUS_CHANGES_REQUESTED)
            ->latest('submitted_at')
            ->first();

        if (! $app) {
            return response()->json([
                'message' => 'No tienes una solicitud en cambios solicitados.',
            ], 404);
        }

        $data = $request->validate([
            'display_name' => 'sometimes|required|string|max:100',
            'pen_name' => 'nullable|string|max:100',
            'bio_long' => 'sometimes|required|string|min:500|max:2000',
            'sample_url' => 'sometimes|required|url|max:500',
            'genres' => 'sometimes|required|array|min:1|max:5',
            'languages' => 'sometimes|required|array|min:1|max:5',
            'why_join' => 'sometimes|required|string|min:250|max:1000',
            'social_links' => 'nullable|array',
            'publish_frequency' => 'nullable|in:weekly,biweekly,monthly,irregular',
            'publish_plan' => 'nullable|string|max:1000',
        ]);

        $app->update([
            ...$data,
            // Vuelve a pending para que el revisor la mire de nuevo
            'status' => CreatorApplication::STATUS_PENDING,
            'submitted_at' => now(),
            // Limpia scoring previo
            'score_quality' => null,
            'score_commitment' => null,
            'score_professionalism' => null,
            'score_fit' => null,
            'score_total' => null,
            'reviewer_notes' => null,
            'reviewed_at' => null,
        ]);

        $user->update(['creator_status' => 'pending']);

        return (new CreatorApplicationResource($app))->response();
    }
}
