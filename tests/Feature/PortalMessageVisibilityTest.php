<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// A client only ever sees message threads they were included on -- not
// staff-only threads or ones between the studio and a colleague -- and
// can't reach them another way (replying, downloading, joining).
class PortalMessageVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function setUpThreads(): array
    {
        Storage::fake('local');
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);
        $manager = User::factory()->create();
        $teammate = User::factory()->teamMember()->create();
        $project->users()->attach($teammate->id, ['assigned_at' => now()]);

        $client = $company->contacts()->create(['name' => 'Casey Client', 'email' => 'casey@example.com']);
        $client->forceFill(['portal_invited_at' => now()])->save();

        $included = $this->actingAs($manager)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'For the client', 'body' => 'Hi Casey', 'recipients' => ["contact:{$client->id}"],
        ])->json();

        $staffOnly = $this->actingAs($manager)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'Internal only', 'body' => 'Just us',
            'recipients' => ["user:{$teammate->id}"],
            'attachments' => [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')],
        ])->json();

        return compact('project', 'client', 'included', 'staffOnly');
    }

    public function test_the_project_page_only_sends_threads_the_client_is_on(): void
    {
        ['project' => $project, 'client' => $client, 'included' => $included] = $this->setUpThreads();

        $response = $this->actingAs($client, 'client')->get("/portal/projects/{$project->id}");

        $subjects = collect($response->viewData('page')['props']['project']['messages'])->pluck('subject');
        $this->assertEquals(['For the client'], $subjects->all());
        $this->assertStringNotContainsString('Internal only', $response->getContent());
    }

    public function test_the_messages_api_only_returns_threads_the_client_is_on(): void
    {
        ['project' => $project, 'client' => $client, 'included' => $included] = $this->setUpThreads();

        $ids = collect($this->actingAs($client, 'client')->getJson("/api/portal/projects/{$project->id}/messages")->json())->pluck('id');

        $this->assertEquals([$included['id']], $ids->all());
    }

    public function test_a_client_can_reply_to_a_thread_they_are_on(): void
    {
        ['client' => $client, 'included' => $included] = $this->setUpThreads();

        $this->actingAs($client, 'client')
            ->postJson("/api/portal/messages/{$included['id']}/replies", ['body' => 'Thanks!'])
            ->assertSuccessful();
    }

    public function test_a_client_cannot_reply_to_a_thread_they_are_not_on(): void
    {
        ['client' => $client, 'staffOnly' => $staffOnly] = $this->setUpThreads();

        $this->actingAs($client, 'client')
            ->postJson("/api/portal/messages/{$staffOnly['id']}/replies", ['body' => 'Sneaky'])
            ->assertForbidden();
    }

    public function test_a_client_cannot_download_an_attachment_from_a_thread_they_are_not_on(): void
    {
        ['client' => $client, 'staffOnly' => $staffOnly] = $this->setUpThreads();
        $attachmentId = $staffOnly['attachments'][0]['id'];

        $this->actingAs($client, 'client')->get("/api/portal/attachments/{$attachmentId}")->assertForbidden();
        $this->actingAs($client, 'client')->get("/api/portal/attachments/{$attachmentId}/thumbnail")->assertForbidden();
    }

    public function test_a_client_cannot_join_a_thread(): void
    {
        ['client' => $client, 'staffOnly' => $staffOnly] = $this->setUpThreads();

        $this->actingAs($client, 'client')
            ->postJson("/api/portal/messages/{$staffOnly['id']}/join")
            ->assertNotFound();
    }
}
