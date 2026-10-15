<?php

namespace App\Http\Controllers\Api\Admin;

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
     * GET /api/admin/creator-applications
     * Lista priorizada (oldest pending first).
     * Filtros: status, search
     */
    public function index(Request $request): JsonResponse
    {
        $query = CreatorApplication::query()
            ->with('user:id,name,username,email,avatar,created_at');

        $status = $request->input('status', 'pending');
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($search = $request->input('search')) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $query->where(function ($w) use ($like) {
                $w->where('display_name', 'ILIKE', $like)
                  ->orWhere('pen_name', 'ILIKE', $like)
                  ->orWhereHas('user', fn($u) => $u->where('username', 'ILIKE', $like)
                      ->orWhere('email', 'ILIKE', $like));
            });
        }

        // Pendientes oldest first; resto newest first
        if ($status === 'pending') {
            $query->orderBy('submitted_at');
        } else {
            $query->latest('submitted_at');
        }

        $apps = $query->paginate(20)->withQueryString();
        return CreatorApplicationResource::collection($apps)->response();
    }

    /**
     * GET /api/admin/creator-applications/{app}
     */
    public function show(CreatorApplication $creatorApplication): JsonResponse
    {
        $creatorApplication->load(['user', 'reviewer']);
        return (new CreatorApplicationResource($creatorApplication))->response();
    }

    /**
     * POST /api/admin/creator-applications/{app}/score
     * Solo guarda el score sin decidir (botón "Guardar borrador").
     */
    public function score(Request $request, CreatorApplication $creatorApplication): JsonResponse
    {
        $data = $request->validate([
            'quality' => 'required|integer|between:0,3',
            'commitment' => 'required|integer|between:0,3',
            'professionalism' => 'required|integer|between:0,3',
            'fit' => 'required|integer|between:0,3',
        ]);

        $total = $this->service->scoreApplication(
            $creatorApplication,
            $request->user(),
            $data['quality'],
            $data['commitment'],
            $data['professionalism'],
            $data['fit'],
        );
        $creatorApplication->save();

        return response()->json([
            'score_total' => $total,
            'can_approve' => $total >= CreatorApplicationService::MIN_SCORE_TO_APPROVE,
        ]);
    }

    /**
     * POST /api/admin/creator-applications/{app}/approve
     */
    public function approve(Request $request, CreatorApplication $creatorApplication): JsonResponse
    {
        $data = $request->validate([
            'notes' => 'nullable|string|max:2000',
        ]);

        try {
            $this->service->approve($creatorApplication, $request->user(), $data['notes'] ?? null);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $creatorApplication->load(['user', 'reviewer']);
        return (new CreatorApplicationResource($creatorApplication))->response();
    }

    /**
     * POST /api/admin/creator-applications/{app}/reject
     */
    public function reject(Request $request, CreatorApplication $creatorApplication): JsonResponse
    {
        $data = $request->validate([
            'notes' => 'required|string|min:20|max:2000',
        ]);

        $this->service->reject($creatorApplication, $request->user(), $data['notes']);

        $creatorApplication->load(['user', 'reviewer']);
        return (new CreatorApplicationResource($creatorApplication))->response();
    }

    /**
     * POST /api/admin/creator-applications/{app}/request-changes
     */
    public function requestChanges(Request $request, CreatorApplication $creatorApplication): JsonResponse
    {
        $data = $request->validate([
            'notes' => 'required|string|min:20|max:2000',
        ]);

        $this->service->requestChanges($creatorApplication, $request->user(), $data['notes']);

        $creatorApplication->load(['user', 'reviewer']);
        return (new CreatorApplicationResource($creatorApplication))->response();
    }
}
