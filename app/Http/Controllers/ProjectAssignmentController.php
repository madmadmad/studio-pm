<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectAssignmentController extends Controller
{
    // Manage projects only: assigning staff is a roster decision, not a thing
    // someone does for themselves -- deliberately not just
    // ProjectPolicy::update, which anyone on the project also passes.
    public function store(Request $request, Project $project)
    {
        $this->authorize('manage', $project);

        $data = $request->validate([
            // Any active staff account is assignable -- super admins
            // included, not just team members.
            'user_id' => ['required', Rule::exists('users', 'id')->whereNull('deactivated_at')],
        ]);

        $project->users()->syncWithoutDetaching([
            $data['user_id'] => ['assigned_at' => now(), 'unassigned_at' => null],
        ]);

        return $project->activeUsers()->get(['users.id', 'users.name', 'users.email', 'users.role', 'users.avatar_path']);
    }

    public function destroy(Request $request, Project $project, User $user)
    {
        $this->authorize('manage', $project);

        $project->users()->updateExistingPivot($user->id, ['unassigned_at' => now()]);

        return response()->noContent();
    }
}
