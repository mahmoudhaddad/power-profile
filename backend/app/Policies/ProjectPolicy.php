<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    /**
     * True if the user owns the project or is listed as any member role.
     * Used for all read operations.
     */
    public function view(User $user, Project $project): bool
    {
        return $project->userRole($user->id) !== null;
    }

    /**
     * True if the user owns the project or is an admin/main member.
     * Used for write operations (add components, configure sources, etc.).
     */
    public function update(User $user, Project $project): bool
    {
        return in_array($project->userRole($user->id), ['admin', 'main'], true);
    }

    /**
     * True only if the user owns the project.
     * Deleting a project is owner-only.
     */
    public function delete(User $user, Project $project): bool
    {
        return $project->user_id === $user->id;
    }
}
