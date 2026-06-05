<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\FinancialAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/projects/{project}/financial-analysis?month=6
 *
 * Returns the full financial profile: annual energy, costs, savings,
 * investment, payback period, and a 25-year cumulative cash-flow projection.
 */
class FinancialController extends Controller
{
    public function __construct(
        private FinancialAnalysisService $financialSvc,
    ) {}

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        if (! $project->userRole($request->user()->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $month = max(1, min(12, (int) $request->query('month', now()->month)));

        $result = $this->financialSvc->analyzeProject($project, $month);

        return response()->json(array_merge($result, ['month' => $month]));
    }
}
