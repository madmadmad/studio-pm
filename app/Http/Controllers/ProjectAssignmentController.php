<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectAssignmentController extends Controller
{
    // Manager-only: assigning staff to projects is a roster decision, not a
    // thing a Team Member does for themselves -- deliberately not just
    // ProjectPolicy::update, which a currently-assigned Team Member also passes.
    public function store(Request $request, Project $project)
    {
        abort_unless($request->user()->isManager(), 403);

        $data = $request->validate([
            // Any active staff account is assignable -- managers included,
            // not just team members.
            'user_id' => ['required', Rule::exists('users', 'id')->whereNull('deactivated_at')],
        ]);

        $project->users()->syncWithoutDetaching([
            $data['user_id'] => ['assigned_at' => now(), 'unassigned_at' => null],
        ]);

        return $project->activeUsers()->get(['users.id', 'users.name', 'users.email']);
    }

    public function destroy(Request $request, Project $project, User $user)
    {
        abort_unless($request->user()->isManager(), 403);

        $project->users()->updateExistingPivot($user->id, ['unassigned_at' => now()]);

        return response()->noContent();
    }
}
