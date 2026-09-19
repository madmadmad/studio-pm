<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TaskFileTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_file_can_be_uploaded_to_a_task(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);
        $task = $project->tasks()->create(['title' => 'Design homepage']);

        $file = UploadedFile::fake()->create('model.step', 500);

        $response = $this->actingAs($user)->postJson("/api/tasks/{$task->id}/files", [
            'file' => $file,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('filename', 'model.step');
        $this->assertDatabaseHas('task_files', ['task_id' => $task->id, 'filename' => 'model.step']);

        $taskFile = $task->files()->first();
        Storage::disk('public')->assertExists($taskFile->path);
    }

    public function test_deleting_a_file_removes_it_from_storage(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);
        $task = $project->tasks()->create(['title' => 'Design homepage']);

        $uploaded = $this->actingAs($user)->postJson("/api/tasks/{$task->id}/files", [
            'file' => UploadedFile::fake()->create('model.step', 500),
        ])->json();

        $response = $this->actingAs($user)->deleteJson("/api/files/{$uploaded['id']}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('task_files', ['id' => $uploaded['id']]);
        Storage::disk('public')->assertMissing($uploaded['path']);
    }

    public function test_deleting_a_task_deletes_its_files(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);
        $task = $project->tasks()->create(['title' => 'Design homepage']);
        $file = $task->files()->create(['path' => 'task-files/x.step', 'filename' => 'x.step', 'size' => 10]);

        $task->delete();

        $this->assertDatabaseMissing('task_files', ['id' => $file->id]);
    }
}
