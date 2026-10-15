<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReportResource;
use App\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /**
     * POST /api/reports
     * Cualquier usuario autenticado puede reportar.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'target_type' => 'required|in:' . implode(',', Report::TARGET_TYPES),
            'target_id' => 'required|integer|min:1',
            'reason' => 'required|in:' . implode(',', Report::REASONS),
            'details' => 'nullable|string|max:2000',
        ]);

        // Anti-duplicado: si el mismo usuario reportó el mismo target en últimas 24h
        $recent = Report::where('reporter_id', $request->user()->id)
            ->where('target_type', $data['target_type'])
            ->where('target_id', $data['target_id'])
            ->where('created_at', '>=', now()->subDay())
            ->exists();

        if ($recent) {
            return response()->json([
                'message' => 'Ya has reportado este contenido recientemente. Lo revisaremos pronto.',
            ], 429);
        }

        $report = Report::create([
            'reporter_id' => $request->user()->id,
            ...$data,
            'status' => Report::STATUS_OPEN,
        ]);

        return (new ReportResource($report))->response()->setStatusCode(201);
    }
}
