<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalActiveMessagesTest extends TestCase
{
    use RefreshDatabase;

    private function setUpClient(): array
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh', 'status' => 'active']);
        $manager = User::factory()->create();
        $client = $company->contacts()->create(['name' => 'Casey Client', 'email' => 'casey@example.com']);
        $client->forceFill(['portal_invited_at' => now()])->save();

        return compact('company', 'project', 'manager', 'client');
    }

    private function thread($manager, $project, array $recipients): int
    {
        return $this->actingAs($manager)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'Logo options',
            'body' => 'See attached',
            'recipients' => $recipients,
        ])->assertCreated()->json('id');
    }

    private function activeMessages($client): int
    {
        return $this->actingAs($client, 'client')->get('/portal')->assertOk()->viewData('page')['props']['activeMessages'];
    }

    public function test_threads_with_a_message_in_the_last_week_count_as_active(): void
    {
        ['project' => $project, 'manager' => $manager, 'client' => $client] = $this->setUpClient();

        $this->travelTo(now()->subDays(10));
        $old = $this->thread($manager, $project, ["contact:{$client->id}"]);
        $this->travelBack();
        $this->thread($manager, $project, ["contact:{$client->id}"]);

        $this->assertSame(1, $this->activeMessages($client));

        // A reply brings the old conversation back to active.
        $this->actingAs($manager)->postJson("/api/messages/{$old}/replies", ['body' => 'Any thoughts?'])->assertCreated();
        $this->assertSame(2, $this->activeMessages($client));

        // A week on, with nothing new, neither is.
        $this->travel(8)->days();
        $this->assertSame(0, $this->activeMessages($client));
    }

    public function test_only_threads_the_client_is_on_count(): void
    {
        ['project' => $project, 'manager' => $manager, 'client' => $client] = $this->setUpClient();
        $teammate = User::factory()->teamMember()->create();
        $project->users()->attach($teammate->id, ['assigned_at' => now()]);

        $this->thread($manager, $project, ["user:{$teammate->id}"]); // staff only
        $this->thread($manager, $project, ["contact:{$client->id}"]);

        $this->assertSame(1, $this->activeMessages($client));
    }

    public function test_a_deleted_thread_does_not_count(): void
    {
        ['project' => $project, 'manager' => $manager, 'client' => $client] = $this->setUpClient();

        $id = $this->thread($manager, $project, ["contact:{$client->id}"]);
        Message::find($id)->delete();

        $this->assertSame(0, $this->activeMessages($client));
    }
}
