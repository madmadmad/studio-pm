<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Message;
use App\Models\User;
use App\Services\UnreadMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UnreadMessagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function projectWithPeople(): array
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh', 'status' => 'active']);
        $author = User::factory()->teamMember()->create();
        $reader = User::factory()->teamMember()->create();
        $bystander = User::factory()->teamMember()->create();
        $project->users()->attach([$author->id, $reader->id, $bystander->id], ['assigned_at' => now()]);
        $contact = $company->contacts()->create(['name' => 'Jo Park', 'email' => 'jo@example.com', 'is_primary' => true]);
        $contact->forceFill(['portal_invited_at' => now()])->save();

        return compact('project', 'author', 'reader', 'bystander', 'contact');
    }

    private function startThread($project, User $author, array $recipients): array
    {
        return $this->actingAs($author)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'Kickoff', 'body' => 'Hello', 'recipients' => $recipients,
        ])->assertCreated()->json();
    }

    public function test_a_new_thread_is_unread_for_its_recipients_until_opened_and_read_for_its_author(): void
    {
        ['project' => $project, 'author' => $author, 'reader' => $reader, 'bystander' => $bystander] = $this->projectWithPeople();
        $thread = $this->startThread($project, $author, ["user:{$reader->id}"]);

        $unreadFor = fn (User $user) => collect($this->actingAs($user)->getJson("/api/projects/{$project->id}/messages")->json())->firstWhere('id', $thread['id'])['unread'];

        $this->assertTrue($unreadFor($reader));
        $this->assertFalse($unreadFor($author));
        // Not on the thread: never "unread", even though they can browse it.
        $this->assertFalse($unreadFor($bystander));

        $this->actingAs($reader)->get('/projects')->assertInertia(fn ($page) => $page->where('projects.0.unread_messages', 1));

        $this->actingAs($reader)->postJson("/api/messages/{$thread['id']}/read")->assertNoContent();

        $this->assertFalse($unreadFor($reader));
        $this->actingAs($reader)->get('/projects')->assertInertia(fn ($page) => $page->where('projects.0.unread_messages', 0));
    }

    public function test_a_reply_makes_the_thread_unread_again_for_everyone_else(): void
    {
        ['project' => $project, 'author' => $author, 'reader' => $reader] = $this->projectWithPeople();
        $thread = $this->startThread($project, $author, ["user:{$reader->id}"]);
        $this->actingAs($reader)->postJson("/api/messages/{$thread['id']}/read");

        $this->travel(1)->minutes();
        $this->actingAs($author)->postJson("/api/messages/{$thread['id']}/replies", ['body' => 'One more thing'])->assertCreated();

        $message = Message::with(Message::threadRelations())->find($thread['id']);
        $this->assertTrue(UnreadMessages::isUnread($message, $reader));
        $this->assertFalse(UnreadMessages::isUnread($message, $author));
        $this->assertSame([$project->id => 1], UnreadMessages::countsByProject($reader));

        // Replying is reading.
        $this->travel(1)->minutes();
        $this->actingAs($reader)->postJson("/api/messages/{$thread['id']}/replies", ['body' => 'Got it'])->assertCreated();
        $this->assertSame([], UnreadMessages::countsByProject($reader));
    }

    public function test_clients_see_and_clear_their_own_unread_threads(): void
    {
        ['project' => $project, 'author' => $author, 'contact' => $contact] = $this->projectWithPeople();
        $thread = $this->startThread($project, $author, ["contact:{$contact->id}"]);

        $this->actingAs($contact, 'client')->get('/portal')
            ->assertInertia(fn ($page) => $page->where('projects.0.unread_messages', 1));
        $this->actingAs($contact, 'client')->get("/portal/projects/{$project->id}")
            ->assertInertia(fn ($page) => $page->where('project.messages.0.unread', true));

        $this->actingAs($contact, 'client')->postJson("/api/portal/messages/{$thread['id']}/read")->assertNoContent();

        $this->assertSame([], UnreadMessages::countsByProject($contact));
    }

    public function test_a_deleted_message_does_not_count_as_unread(): void
    {
        ['project' => $project, 'author' => $author, 'reader' => $reader] = $this->projectWithPeople();
        $thread = $this->startThread($project, $author, ["user:{$reader->id}"]);

        $this->actingAs($author)->deleteJson("/api/messages/{$thread['id']}")->assertNoContent();

        $this->assertSame([], UnreadMessages::countsByProject($reader));
    }
}
