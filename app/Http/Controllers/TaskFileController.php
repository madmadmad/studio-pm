<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ServesPrivateFile;
use App\Models\Task;
use App\Models\TaskFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TaskFileController extends Controller
{
    use ServesPrivateFile;

    public function store(Request $request, Task $task)
    {
        $this->authorize('update', $task);

        $request->validate([
            'file' => ['required', 'file', 'max:20480'], // 20MB
        ]);

        $uploaded = $request->file('file');
        $disk = config('filesystems.private_disk');
        $path = $uploaded->store('task-files', $disk);

        return $task->files()->create([
            'disk' => $disk,
            'path' => $path,
            'filename' => $uploaded->getClientOriginalName(),
            'mime_type' => $uploaded->getClientMimeType(),
            'size' => $uploaded->getSize(),
        ]);
    }

    // The file, for staff who can see its task.
    public function show(TaskFile $file)
    {
        $this->authorize('view', $file->task);

        return $this->respondWithPrivateFile($file->disk, $file->path, $file->filename);
    }

    public function destroy(TaskFile $file)
    {
        $this->authorize('update', $file->task);

        Storage::disk($file->disk)->delete($file->path);
        $file->delete();

        return response()->noContent();
    }
}
