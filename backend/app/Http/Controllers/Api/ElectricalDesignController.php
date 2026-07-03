<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\ElectricalDesignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/projects/{project}/electrical-design
 *
 * Returns IEC 60364 panel schedules (circuit lists, breaker sizing, cable
 * sizing, phase assignment) for every building in the project.
 */
class ElectricalDesignController extends Controller
{
    public function __construct(
        private ElectricalDesignService $designSvc,
    ) {}

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        if (! $project->userRole($request->user()->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $result = $this->designSvc->analyzeProject($project);

        return response()->json($result);
    }
}
