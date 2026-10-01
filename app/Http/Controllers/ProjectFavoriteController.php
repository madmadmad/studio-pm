<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\Request;

// Starring a project (the star on the Projects list, board and project
// page): each person's own favorites, for filtering and sorting the list.
class ProjectFavoriteController extends Controller
{
    public function store(Request $request, Project $project)
    {
        $this->authorize('view', $project);

        $request->user()->favoriteProjects()->syncWithoutDetaching([$project->id]);

        return response()->json(['is_favorite' => true]);
    }

    public function destroy(Request $request, Project $project)
    {
        $request->user()->favoriteProjects()->detach($project->id);

        return response()->json(['is_favorite' => false]);
    }
}
