<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ServerBackup;
use Illuminate\Http\Request;

class ServerBackupController extends Controller
{
    /** List server backups for a project, optionally filtered by entity type/id. */
    public function index(Request $request, Project $project)
    {
        if (! in_array($project->userRole($request->user()->id), ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $query = ServerBackup::where('project_id', $project->id)
            ->orderByDesc('created_at');

        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->entity_type);
        }

        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->entity_id);
        }

        return response()->json([
            'data' => $query->get(['id', 'entity_type', 'entity_id', 'entity_name', 'created_at']),
        ]);
    }

    /** Return the full backup data for a single server backup. */
    public function show(Request $request, ServerBackup $backup)
    {
        $backup->load('project');
        if (! in_array($backup->project->userRole($request->user()->id), ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json(['data' => json_decode($backup->data, true)]);
    }

    /** Delete a server backup. */
    public function destroy(Request $request, ServerBackup $backup)
    {
        $backup->load('project');
        if (! in_array($backup->project->userRole($request->user()->id), ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $backup->delete();

        return response()->json(['message' => 'Server backup deleted.']);
    }
}
