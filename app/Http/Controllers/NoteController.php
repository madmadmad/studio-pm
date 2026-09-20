<?php

namespace App\Http\Controllers;

use App\Models\Note;
use App\Models\Project;
use Illuminate\Http\Request;

class NoteController extends Controller
{
    public function index(Project $project)
    {
        $this->authorize('view', $project);

        return $project->notes;
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
        ]);

        return $project->notes()->create($data);
    }

    public function update(Request $request, Note $note)
    {
        $this->authorize('update', $note->project);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
        ]);

        $note->update($data);

        return $note;
    }

    public function destroy(Note $note)
    {
        $this->authorize('update', $note->project);

        $note->delete();

        return response()->noContent();
    }
}
