<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Concerns\ServesPrivateFile;
use App\Http\Controllers\Controller;
use App\Models\TaskFile;
use App\Policies\Portal\TaskPolicy;
use Illuminate\Http\Request;

// A task's file, for a client -- only on a task the studio shows them.
class TaskFileController extends Controller
{
    use ServesPrivateFile;

    public function __construct(protected TaskPolicy $policy) {}

    public function show(Request $request, TaskFile $file)
    {
        abort_unless($this->policy->view($request->user(), $file->task), 403);

        return $this->respondWithPrivateFile($file->disk, $file->path, $file->filename);
    }
}
