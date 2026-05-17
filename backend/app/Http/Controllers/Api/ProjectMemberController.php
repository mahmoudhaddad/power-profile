<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use Illuminate\Http\Request;

class ProjectMemberController extends Controller
{
    /** Only the project admin (owner) may manage members. */
    private function requireAdmin(Request $request, Project $project)
    {
        if ($project->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden. Only the project admin can manage members.'], 403);
        }
        return null;
    }

    /** List all members (admin + added users). */
    public function index(Request $request, Project $project)
    {
        if ($deny = $this->requireAdmin($request, $project)) return $deny;

        $members = $project->projectUsers()->with('user:id,name,email,avatar')->get()
            ->map(fn($pu) => [
                'id'     => $pu->id,
                'role'   => $pu->role,
                'user'   => $pu->user,
            ]);

        return response()->json([
            'admin'   => $request->user()->only('id', 'name', 'email', 'avatar'),
            'members' => $members,
        ]);
    }

    /** Add a user to the project by email. */
    public function store(Request $request, Project $project)
    {
        if ($deny = $this->requireAdmin($request, $project)) return $deny;

        $request->validate([
            'email' => 'required|email|exists:users,email',
            'role'  => 'required|in:admin,main,normal',
        ]);

        $user = User::where('email', $request->email)->firstOrFail();

        if ($user->id === $project->user_id) {
            return response()->json(['message' => 'This user is already the project owner.'], 422);
        }

        $member = ProjectUser::updateOrCreate(
            ['project_id' => $project->id, 'user_id' => $user->id],
            ['role' => $request->role]
        );

        return response()->json([
            'data' => [
                'id'   => $member->id,
                'role' => $member->role,
                'user' => $user->only('id', 'name', 'email', 'avatar'),
            ],
        ], 201);
    }

    /** Change a member's role. */
    public function update(Request $request, Project $project, ProjectUser $member)
    {
        if ($deny = $this->requireAdmin($request, $project)) return $deny;

        if ($member->project_id !== $project->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $request->validate(['role' => 'required|in:admin,main,normal']);

        $member->update(['role' => $request->role]);

        return response()->json(['data' => $member->load('user:id,name,email,avatar')]);
    }

    /** Remove a member from the project. */
    public function destroy(Request $request, Project $project, ProjectUser $member)
    {
        if ($deny = $this->requireAdmin($request, $project)) return $deny;

        if ($member->project_id !== $project->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $member->delete();

        return response()->json(['message' => 'Member removed.']);
    }
}
