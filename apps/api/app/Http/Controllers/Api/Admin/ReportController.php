<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReportResource;
use App\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /**
     * GET /api/admin/reports
     * Filtros: status (default 'open'), target_type, reason
     */
    public function index(Request $request): JsonResponse
    {
        $query = Report::query()->with(['reporter:id,name,username,avatar', 'resolver:id,name']);

        $status = $request->input('status', 'open');
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($type = $request->input('target_type')) {
            $query->where('target_type', $type);
        }

        if ($reason = $request->input('reason')) {
            $query->where('reason', $reason);
        }

        // open primero más antiguos (más urgente); resto recientes
        if ($status === 'open') {
            $query->orderBy('created_at');
        } else {
            $query->latest('created_at');
        }

        $reports = $query->paginate(30)->withQueryString();
        return ReportResource::collection($reports)->response();
    }

    /**
     * GET /api/admin/reports/{report}
     * Detalle con datos del target embebidos.
     */
    public function show(Report $report): JsonResponse
    {
        $report->load(['reporter', 'resolver']);

        $target = $report->target();
        $targetData = $target ? [
            'type' => $report->target_type,
            'id' => $report->target_id,
            'preview' => $this->buildPreview($report->target_type, $target),
        ] : null;

        return response()->json([
            'data' => new ReportResource($report),
            'target' => $targetData,
        ]);
    }

    /**
     * PATCH /api/admin/reports/{report}/resolve
     */
    public function resolve(Request $request, Report $report): JsonResponse
    {
        $data = $request->validate([
            'resolution_action' => 'required|in:' . implode(',', Report::RESOLUTION_ACTIONS),
            'resolution_notes' => 'nullable|string|max:2000',
        ]);

        $report->update([
            'status' => Report::STATUS_RESOLVED,
            'resolved_by_id' => $request->user()->id,
            'resolution_action' => $data['resolution_action'],
            'resolution_notes' => $data['resolution_notes'] ?? null,
            'resolved_at' => now(),
        ]);

        // TODO: ejecutar la acción (suspend user, hide content, etc.)
        // Lo dejamos como log por ahora; la ejecución real va en un job.

        $report->load(['reporter', 'resolver']);
        return (new ReportResource($report))->response();
    }

    /**
     * PATCH /api/admin/reports/{report}/dismiss
     */
    public function dismiss(Request $request, Report $report): JsonResponse
    {
        $data = $request->validate([
            'resolution_notes' => 'nullable|string|max:2000',
        ]);

        $report->update([
            'status' => Report::STATUS_DISMISSED,
            'resolved_by_id' => $request->user()->id,
            'resolution_action' => 'none',
            'resolution_notes' => $data['resolution_notes'] ?? null,
            'resolved_at' => now(),
        ]);

        $report->load(['reporter', 'resolver']);
        return (new ReportResource($report))->response();
    }

    /**
     * Construye un preview cortito del contenido reportado.
     */
    private function buildPreview(string $type, $target): array
    {
        return match ($type) {
            'book' => [
                'title' => $target->title,
                'slug' => $target->slug,
                'cover' => $target->cover ?? null,
            ],
            'user' => [
                'name' => $target->name,
                'username' => $target->username,
                'avatar' => $target->avatar,
            ],
            'review' => [
                'rating' => $target->rating,
                'title' => $target->title,
                'excerpt' => mb_substr($target->comment ?? '', 0, 200),
            ],
            default => ['raw' => $target->toArray()],
        };
    }
}
