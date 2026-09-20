<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TaskFileController extends Controller
{
    public function store(Request $request, Task $task)
    {
        $this->authorize('update', $task);

        $request->validate([
            'file' => ['required', 'file', 'max:20480'], // 20MB
        ]);

        $uploaded = $request->file('file');
        $path = $uploaded->store('task-files', 'public');

        return $task->files()->create([
            'path' => $path,
            'filename' => $uploaded->getClientOriginalName(),
            'mime_type' => $uploaded->getClientMimeType(),
            'size' => $uploaded->getSize(),
        ]);
    }

    public function destroy(TaskFile $file)
    {
        $this->authorize('update', $file->task);

        Storage::disk('public')->delete($file->path);
        $file->delete();

        return response()->noContent();
    }
}
