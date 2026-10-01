<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MessageLinksTest extends TestCase
{
    use RefreshDatabase;

    private function setUpProject(): array
    {
        Notification::fake();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh', 'status' => 'active']);
        $author = User::factory()->teamMember()->create();
        $other = User::factory()->teamMember()->create();
        $project->users()->attach([$author->id, $other->id], ['assigned_at' => now()]);
        $contact = $company->contacts()->create(['name' => 'Jo Park', 'email' => 'jo@example.com', 'is_primary' => true]);
        $contact->forceFill(['portal_invited_at' => now()])->save();

        return compact('project', 'author', 'other', 'contact');
    }

    public function test_a_message_can_share_just_a_link(): void
    {
        ['project' => $project, 'author' => $author, 'other' => $other] = $this->setUpProject();
        $link = 'https://www.dropbox.com/scl/fo/abc123/Brand_Assets.zip?dl=0';

        $thread = $this->actingAs($author)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'Files', 'recipients' => ["user:{$other->id}"], 'links' => [$link],
        ])->assertCreated()->assertJsonPath('links.0.url', $link)->json();

        $this->actingAs($author)->postJson("/api/messages/{$thread['id']}/replies", [
            'links' => ['https://drive.google.com/file/d/xyz/view', 'https://drive.google.com/file/d/xyz/view'],
        ])->assertCreated()->assertJsonCount(1, 'links');

        $threads = $this->actingAs($other)->getJson("/api/projects/{$project->id}/messages")->json();
        $this->assertSame($link, $threads[0]['links'][0]['url']);
        $this->assertSame('https://drive.google.com/file/d/xyz/view', $threads[0]['replies'][0]['links'][0]['url']);
        $this->assertSame('Shared 1 link', Message::with('attachments', 'links')->find($thread['id'])->snippet());
    }

    public function test_only_web_links_are_accepted_and_a_message_still_needs_something(): void
    {
        ['project' => $project, 'author' => $author, 'other' => $other] = $this->setUpProject();
        $base = ['subject' => 'Files', 'recipients' => ["user:{$other->id}"]];

        $this->actingAs($author)->postJson("/api/projects/{$project->id}/messages", [...$base, 'links' => ['javascript:alert(1)']])
            ->assertUnprocessable()->assertJsonValidationErrors('links.0');
        $this->actingAs($author)->postJson("/api/projects/{$project->id}/messages", [...$base, 'links' => ['not a link']])
            ->assertUnprocessable();
        $this->actingAs($author)->postJson("/api/projects/{$project->id}/messages", $base)
            ->assertUnprocessable()->assertJsonValidationErrors('body');
    }

    public function test_clients_can_share_links_from_the_portal(): void
    {
        ['project' => $project, 'author' => $author, 'contact' => $contact] = $this->setUpProject();

        $this->actingAs($contact, 'client')->postJson("/api/portal/projects/{$project->id}/messages", [
            'subject' => 'Our photos', 'recipients' => ["user:{$author->id}"], 'links' => ['https://we.tl/t-abc123'],
        ])->assertCreated()->assertJsonPath('links.0.url', 'https://we.tl/t-abc123');
    }
}
