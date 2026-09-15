<?php

namespace App\Http\Controllers;

use App\Models\Note;
use App\Models\Project;
use Illuminate\Http\Request;

class NoteController extends Controller
{
    public function index(Project $project)
    {
        return $project->notes;
    }

    public function store(Request $request, Project $project)
    {
        $data = $request->validate([
            'body' => ['required', 'string'],
        ]);

        return $project->notes()->create($data);
    }

    public function destroy(Note $note)
    {
        $note->delete();

        return response()->noContent();
    }
}
