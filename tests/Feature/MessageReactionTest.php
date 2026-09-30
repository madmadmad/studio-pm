<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Emoji reactions on messages: anyone who can reply to a thread can react,
// each emoji toggles, and only the fixed set is accepted.
class MessageReactionTest extends TestCase
{
    use RefreshDatabase;

    private function setUpThread(): array
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);
        $manager = User::factory()->create();
        $teammate = User::factory()->teamMember()->create();
        $outsider = User::factory()->teamMember()->create(); // not on this project
        $project->users()->attach($teammate->id, ['assigned_at' => now()]);

        $client = $company->contacts()->create(['name' => 'Casey Client', 'email' => 'casey@example.com']);
        $client->forceFill(['portal_invited_at' => now()])->save();
        $otherClient = $company->contacts()->create(['name' => 'Olive Other', 'email' => 'olive@example.com']);
        $otherClient->forceFill(['portal_invited_at' => now()])->save();

        $thread = $this->actingAs($manager)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'Kickoff', 'body' => 'Hi all', 'recipients' => ["user:{$teammate->id}", "contact:{$client->id}"],
        ])->json();

        return compact('project', 'manager', 'teammate', 'outsider', 'client', 'otherClient', 'thread');
    }

    public function test_a_reaction_toggles_on_and_off(): void
    {
        ['teammate' => $teammate, 'thread' => $thread] = $this->setUpThread();

        $this->actingAs($teammate)->postJson("/api/messages/{$thread['id']}/reactions", ['emoji' => '👍'])
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.emoji', '👍')->assertJsonPath('0.user.id', $teammate->id);
        $this->assertDatabaseHas('message_reactions', ['message_id' => $thread['id'], 'user_id' => $teammate->id, 'emoji' => '👍']);

        $this->actingAs($teammate)->postJson("/api/messages/{$thread['id']}/reactions", ['emoji' => '👍'])
            ->assertOk()->assertJsonCount(0);
        $this->assertDatabaseCount('message_reactions', 0);
    }

    public function test_only_the_fixed_emoji_are_accepted(): void
    {
        ['teammate' => $teammate, 'thread' => $thread] = $this->setUpThread();

        $this->actingAs($teammate)->postJson("/api/messages/{$thread['id']}/reactions", ['emoji' => '🦄'])->assertStatus(422);
        $this->assertDatabaseCount('message_reactions', 0);
    }

    public function test_someone_off_the_project_cannot_react(): void
    {
        ['outsider' => $outsider, 'thread' => $thread] = $this->setUpThread();

        $this->actingAs($outsider)->postJson("/api/messages/{$thread['id']}/reactions", ['emoji' => '👍'])->assertForbidden();
    }

    public function test_a_client_on_the_thread_can_react_but_one_off_it_cannot(): void
    {
        ['client' => $client, 'otherClient' => $otherClient, 'thread' => $thread] = $this->setUpThread();

        $this->actingAs($client, 'client')->postJson("/api/portal/messages/{$thread['id']}/reactions", ['emoji' => '🎉'])
            ->assertOk()->assertJsonPath('0.contact.id', $client->id);

        $this->actingAs($otherClient, 'client')->postJson("/api/portal/messages/{$thread['id']}/reactions", ['emoji' => '🎉'])
            ->assertForbidden();
    }

    public function test_a_deleted_message_cannot_be_reacted_to(): void
    {
        ['manager' => $manager, 'teammate' => $teammate, 'thread' => $thread] = $this->setUpThread();
        $this->actingAs($manager)->deleteJson("/api/messages/{$thread['id']}")->assertNoContent();

        $this->actingAs($teammate)->postJson("/api/messages/{$thread['id']}/reactions", ['emoji' => '👍'])->assertNotFound();
    }

    public function test_reactions_come_back_with_the_thread(): void
    {
        ['project' => $project, 'manager' => $manager, 'teammate' => $teammate, 'thread' => $thread] = $this->setUpThread();
        $this->actingAs($teammate)->postJson("/api/messages/{$thread['id']}/reactions", ['emoji' => '❤️'])->assertOk();

        $threads = $this->actingAs($manager)->getJson("/api/projects/{$project->id}/messages")->json();

        $this->assertSame('❤️', collect($threads)->firstWhere('id', $thread['id'])['reactions'][0]['emoji']);
    }
}
