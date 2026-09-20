<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Notifications\NewMessageReply;
use App\Notifications\NewMessageThread;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MessageThreadTest extends TestCase
{
    use RefreshDatabase;

    private function makeProjectWithPeople(): array
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);

        $manager = User::factory()->create(['name' => 'Bill Manager']);
        $teammate = User::factory()->teamMember()->create(['name' => 'Robin Teammate']);
        $bystander = User::factory()->teamMember()->create(['name' => 'Sam Bystander']);
        $project->users()->attach([$teammate->id, $bystander->id], ['assigned_at' => now()]);

        $client = $company->contacts()->create(['name' => 'Casey Client', 'email' => 'casey@example.com']);
        $client->forceFill(['portal_invited_at' => now()])->save();

        return compact('company', 'project', 'manager', 'teammate', 'bystander', 'client');
    }

    public function test_creating_a_thread_notifies_only_selected_recipients(): void
    {
        Notification::fake();
        ['project' => $project, 'manager' => $manager, 'teammate' => $teammate, 'bystander' => $bystander, 'client' => $client] = $this->makeProjectWithPeople();

        $response = $this->actingAs($manager)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'Kickoff',
            'body' => "Let's get started",
            'recipients' => ["user:{$teammate->id}", "contact:{$client->id}"],
        ]);

        $response->assertCreated();

        Notification::assertSentTo($teammate, NewMessageThread::class);
        Notification::assertSentTo($client, NewMessageThread::class);
        Notification::assertNotSentTo($manager, NewMessageThread::class); // sender doesn't notify themselves
        Notification::assertNotSentTo($bystander, NewMessageThread::class); // never tagged, never notified

        $this->assertDatabaseCount('message_participants', 3); // sender + 2 recipients
    }

    public function test_replying_notifies_all_current_participants_except_the_replier(): void
    {
        Notification::fake();
        ['project' => $project, 'manager' => $manager, 'teammate' => $teammate, 'client' => $client] = $this->makeProjectWithPeople();

        $thread = $this->actingAs($manager)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'Kickoff',
            'body' => "Let's get started",
            'recipients' => ["user:{$teammate->id}", "contact:{$client->id}"],
        ])->json();

        Notification::fake(); // reset so we only assert on the reply below

        $this->actingAs($teammate)->postJson("/api/messages/{$thread['id']}/replies", [
            'body' => 'Sounds good!',
        ])->assertCreated();

        Notification::assertSentTo($manager, NewMessageReply::class);
        Notification::assertSentTo($client, NewMessageReply::class);
        Notification::assertNotSentTo($teammate, NewMessageReply::class); // the replier never notifies themselves
    }

    public function test_a_user_not_originally_selected_can_join_a_thread_and_then_gets_notified(): void
    {
        ['project' => $project, 'manager' => $manager, 'teammate' => $teammate, 'bystander' => $bystander] = $this->makeProjectWithPeople();

        $thread = $this->actingAs($manager)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'Kickoff',
            'body' => "Let's get started",
            'recipients' => ["user:{$teammate->id}"],
        ])->json();

        $this->assertDatabaseMissing('message_participants', ['message_id' => $thread['id'], 'user_id' => $bystander->id]);

        $this->actingAs($bystander)->postJson("/api/messages/{$thread['id']}/join")->assertOk();

        $this->assertDatabaseHas('message_participants', ['message_id' => $thread['id'], 'user_id' => $bystander->id]);

        Notification::fake();
        $this->actingAs($manager)->postJson("/api/messages/{$thread['id']}/replies", ['body' => 'Update for everyone'])->assertCreated();

        Notification::assertSentTo($bystander, NewMessageReply::class);
    }

    public function test_replying_without_joining_first_implicitly_makes_you_a_participant(): void
    {
        ['project' => $project, 'manager' => $manager, 'teammate' => $teammate, 'bystander' => $bystander] = $this->makeProjectWithPeople();

        $thread = $this->actingAs($manager)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'Kickoff',
            'body' => "Let's get started",
            'recipients' => ["user:{$teammate->id}"],
        ])->json();

        $this->actingAs($bystander)->postJson("/api/messages/{$thread['id']}/replies", ['body' => 'Jumping in'])->assertCreated();

        $this->assertDatabaseHas('message_participants', ['message_id' => $thread['id'], 'user_id' => $bystander->id]);
    }

    public function test_sender_name_and_timestamp_are_attached_to_the_thread_and_every_reply(): void
    {
        ['project' => $project, 'manager' => $manager, 'teammate' => $teammate] = $this->makeProjectWithPeople();

        $thread = $this->actingAs($manager)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'Kickoff',
            'body' => "Let's get started",
            'recipients' => ["user:{$teammate->id}"],
        ])->json();

        $this->assertSame('Bill Manager', $thread['sender_user']['name']);
        $this->assertNotNull($thread['sent_at']);

        $reply = $this->actingAs($teammate)->postJson("/api/messages/{$thread['id']}/replies", ['body' => 'On it'])->json();

        $this->assertSame('Robin Teammate', $reply['sender_user']['name']);
        $this->assertNotNull($reply['sent_at']);
    }

    public function test_a_client_recipients_notification_email_links_to_a_magic_link_not_a_password_login(): void
    {
        Notification::fake();
        ['project' => $project, 'manager' => $manager, 'client' => $client] = $this->makeProjectWithPeople();

        $this->actingAs($manager)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'Kickoff',
            'body' => "Let's get started",
            'recipients' => ["contact:{$client->id}"],
        ])->assertCreated();

        Notification::assertSentTo($client, NewMessageThread::class, function ($notification, $channels, $notifiable) {
            $mail = $notification->toMail($notifiable);

            return str_contains($mail->actionUrl, '/portal/login/verify/') && str_contains($mail->actionUrl, 'redirect=');
        });
    }

    public function test_someone_not_on_the_project_cannot_start_a_thread(): void
    {
        ['project' => $project, 'teammate' => $teammate] = $this->makeProjectWithPeople();
        $outsider = User::factory()->teamMember()->create();

        $this->actingAs($outsider)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'Kickoff',
            'body' => 'Hi',
            'recipients' => ["user:{$teammate->id}"],
        ])->assertForbidden();
    }

    public function test_an_ineligible_recipient_is_rejected(): void
    {
        ['project' => $project, 'manager' => $manager] = $this->makeProjectWithPeople();
        $unrelatedUser = User::factory()->create();

        $this->actingAs($manager)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'Kickoff',
            'body' => 'Hi',
            'recipients' => ["user:{$unrelatedUser->id}"],
        ])->assertStatus(422);
    }

    public function test_a_client_at_the_same_company_can_be_a_recipient(): void
    {
        Notification::fake();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);
        $manager = User::factory()->create();

        $client = $company->contacts()->create(['name' => 'Casey Client', 'email' => 'casey@example.com']);
        $client->forceFill(['portal_invited_at' => now()])->save();

        $this->actingAs($manager)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'Kickoff',
            'body' => 'Hi',
            'recipients' => ["contact:{$client->id}"],
        ])->assertCreated();

        Notification::assertSentTo($client, NewMessageThread::class);
    }
}
