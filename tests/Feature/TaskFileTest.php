<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\TaskFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Task files live on the private disk and are only ever handed out through
// the app: to staff who can see the task, and to a client only on a task
// that's shown to them.
class TaskFileTest extends TestCase
{
    use RefreshDatabase;

    private function task(bool $visibleToClient = false)
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);

        return $project->tasks()->create(['title' => 'Design homepage', 'visible_to_client' => $visibleToClient]);
    }

    private function upload(User $user, $task): TaskFile
    {
        $id = $this->actingAs($user)->postJson("/api/tasks/{$task->id}/files", [
            'file' => UploadedFile::fake()->create('model.step', 500),
        ])->assertCreated()->json('id');

        return TaskFile::findOrFail($id);
    }

    public function test_a_file_is_uploaded_to_the_private_disk(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $task = $this->task();

        $response = $this->actingAs($user)->postJson("/api/tasks/{$task->id}/files", ['file' => UploadedFile::fake()->create('model.step', 500)]);

        $response->assertCreated()
            ->assertJsonPath('filename', 'model.step')
            // A link through the app, never where it's stored.
            ->assertJsonPath('url', route('task-files.show', $response->json('id')))
            ->assertJsonMissingPath('path');
        $file = TaskFile::findOrFail($response->json('id'));
        $this->assertSame('local', $file->disk);
        Storage::disk('local')->assertExists($file->path);
        Storage::disk('public')->assertMissing($file->path);
    }

    public function test_staff_who_can_see_the_task_can_download_it_and_others_cant(): void
    {
        Storage::fake('local');
        $manager = User::factory()->create();
        $outsider = User::factory()->teamMember()->create(); // not on the project
        $file = $this->upload($manager, $this->task());

        $this->actingAs($manager, 'web')->get("/task-files/{$file->id}")->assertOk();
        $this->actingAs($outsider, 'web')->get("/task-files/{$file->id}")->assertForbidden();
        auth()->logout();
        $this->get("/task-files/{$file->id}")->assertRedirect('/login');
    }

    public function test_a_client_downloads_only_from_tasks_shown_to_them(): void
    {
        Storage::fake('local');
        $manager = User::factory()->create();
        $shown = $this->task(visibleToClient: true);
        $hidden = $shown->project->tasks()->create(['title' => 'Internal', 'visible_to_client' => false]);
        $shownFile = $this->upload($manager, $shown);
        $hiddenFile = $this->upload($manager, $hidden);

        $client = $shown->project->company->contacts()->create(['name' => 'Rosa Alder', 'email' => 'rosa@alderfinch.co']);
        $client->forceFill(['portal_invited_at' => now()])->save();
        $stranger = Company::create(['name' => 'Marsh Grove'])->contacts()->create(['name' => 'Olive', 'email' => 'olive@example.com']);
        $stranger->forceFill(['portal_invited_at' => now()])->save();

        $this->actingAs($client, 'client')->get("/portal/task-files/{$shownFile->id}")->assertOk();
        $this->actingAs($client, 'client')->get("/portal/task-files/{$hiddenFile->id}")->assertForbidden();
        $this->actingAs($stranger, 'client')->get("/portal/task-files/{$shownFile->id}")->assertForbidden();
        // And never through the staff door (signed in as the client alone,
        // not still as the manager who uploaded).
        auth()->forgetGuards();
        $this->actingAs($client, 'client')->get("/task-files/{$shownFile->id}")->assertRedirect('/login');
    }

    public function test_an_older_file_on_the_public_disk_still_downloads(): void
    {
        Storage::fake('public');
        $manager = User::factory()->create();
        $task = $this->task();
        Storage::disk('public')->put('task-files/old.step', 'x');
        $file = $task->files()->create(['disk' => 'public', 'path' => 'task-files/old.step', 'filename' => 'old.step', 'size' => 1]);

        $this->actingAs($manager, 'web')->get("/task-files/{$file->id}")->assertOk();
    }

    public function test_deleting_a_file_removes_it_from_storage(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $file = $this->upload($user, $this->task());

        $this->actingAs($user)->deleteJson("/api/files/{$file->id}")->assertNoContent();

        $this->assertDatabaseMissing('task_files', ['id' => $file->id]);
        Storage::disk('local')->assertMissing($file->path);
    }

    public function test_deleting_a_task_deletes_its_files(): void
    {
        $task = $this->task();
        $file = $task->files()->create(['path' => 'task-files/x.step', 'filename' => 'x.step', 'size' => 10]);

        $task->delete();

        $this->assertDatabaseMissing('task_files', ['id' => $file->id]);
    }
}
