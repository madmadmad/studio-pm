<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Tasks are internal unless the studio turns on "Show client"; only those
// reach the Client Hub, in any form.
class TaskClientVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function setUpProject(): array
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh', 'status' => 'active']);
        $client = $company->contacts()->create(['name' => 'Casey Client', 'email' => 'casey@example.com']);
        $client->forceFill(['portal_invited_at' => now()])->save();

        $shown = $project->tasks()->create(['title' => 'Review logo options', 'visible_to_client' => true]);
        $internal = $project->tasks()->create(['title' => 'Internal budget check']);

        return compact('project', 'client', 'shown', 'internal');
    }

    public function test_new_tasks_are_internal_by_default(): void
    {
        ['internal' => $internal] = $this->setUpProject();

        $this->assertFalse($internal->fresh()->visible_to_client);
    }

    public function test_staff_can_turn_show_client_on_and_off(): void
    {
        ['internal' => $task] = $this->setUpProject();
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson("/api/tasks/{$task->id}", ['visible_to_client' => true])->assertOk();
        $this->assertTrue($task->fresh()->visible_to_client);

        $this->actingAs($user)->patchJson("/api/tasks/{$task->id}", ['visible_to_client' => false])->assertOk();
        $this->assertFalse($task->fresh()->visible_to_client);
    }

    public function test_the_portal_project_page_only_sends_shown_tasks(): void
    {
        ['project' => $project, 'client' => $client] = $this->setUpProject();

        $response = $this->actingAs($client, 'client')->get("/portal/projects/{$project->id}");

        $titles = collect($response->viewData('page')['props']['project']['tasks'])->pluck('title');
        $this->assertEquals(['Review logo options'], $titles->all());
        $this->assertStringNotContainsString('Internal budget check', $response->getContent());
    }

    public function test_the_portal_project_list_only_counts_shown_tasks(): void
    {
        ['client' => $client] = $this->setUpProject();

        $projects = $this->actingAs($client, 'client')->get('/portal')->viewData('page')['props']['projects'];

        $this->assertCount(1, $projects[0]['tasks']);
    }

    public function test_a_client_cannot_update_an_internal_task(): void
    {
        ['client' => $client, 'internal' => $internal, 'shown' => $shown] = $this->setUpProject();

        $this->actingAs($client, 'client')->patchJson("/api/portal/tasks/{$internal->id}", ['status' => 'done'])->assertForbidden();
        $this->actingAs($client, 'client')->patchJson("/api/portal/tasks/{$shown->id}", ['status' => 'done'])->assertOk();
    }

    // A client's own task is one they can see.
    public function test_a_task_a_client_adds_is_shown_to_the_client(): void
    {
        ['project' => $project, 'client' => $client] = $this->setUpProject();

        $response = $this->actingAs($client, 'client')->postJson("/api/portal/projects/{$project->id}/tasks", ['title' => 'Send brand guide']);

        $response->assertCreated();
        $this->assertTrue($project->tasks()->find($response->json('id'))->visible_to_client);
    }
}
