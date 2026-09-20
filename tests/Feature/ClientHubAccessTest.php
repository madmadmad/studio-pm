<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientHubAccessTest extends TestCase
{
    use RefreshDatabase;

    private function portalContact(): Contact
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $contact = $company->contacts()->create(['name' => 'Rosa Alder', 'email' => 'rosa@alderfinch.co']);

        $contact->forceFill(['portal_invited_at' => now()])->save();

        return $contact;
    }

    public function test_a_client_sees_only_their_own_companys_projects(): void
    {
        $contact = $this->portalContact();
        $mine = $contact->company->projects()->create(['name' => 'Brand refresh', 'status' => 'active']);
        $otherCompany = Company::create(['name' => 'Marsh Grove Bakery']);
        $notMine = $otherCompany->projects()->create(['name' => 'Not yours', 'status' => 'active']);

        $response = $this->actingAs($contact, 'client')->get('/portal');

        $response->assertOk();
        $ids = collect($response->viewData('page')['props']['projects'])->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($notMine->id));
    }

    public function test_the_project_list_only_shows_active_projects(): void
    {
        $contact = $this->portalContact();
        $active = $contact->company->projects()->create(['name' => 'Active one', 'status' => 'active']);
        foreach (['leads', 'estimated', 'inactive', 'completed', 'archived'] as $status) {
            $contact->company->projects()->create(['name' => "A {$status} project", 'status' => $status]);
        }

        $response = $this->actingAs($contact, 'client')->get('/portal');

        $ids = collect($response->viewData('page')['props']['projects'])->pluck('id');
        $this->assertEquals([$active->id], $ids->all());
    }

    public function test_a_client_cannot_view_a_project_outside_their_company(): void
    {
        $contact = $this->portalContact();
        $otherCompany = Company::create(['name' => 'Marsh Grove Bakery']);
        $project = $otherCompany->projects()->create(['name' => 'Not yours']);

        $this->actingAs($contact, 'client')->get("/portal/projects/{$project->id}")->assertForbidden();
    }

    public function test_a_client_can_create_and_update_a_task_on_their_project(): void
    {
        $contact = $this->portalContact();
        $project = $contact->company->projects()->create(['name' => 'Brand refresh']);

        $response = $this->actingAs($contact, 'client')
            ->postJson("/api/portal/projects/{$project->id}/tasks", ['title' => 'Send logo files']);
        $response->assertCreated();

        $this->actingAs($contact, 'client')
            ->patchJson("/api/portal/tasks/{$response->json('id')}", ['status' => 'done'])
            ->assertOk();
    }

    public function test_a_client_cannot_create_a_task_on_a_project_outside_their_company(): void
    {
        $contact = $this->portalContact();
        $otherCompany = Company::create(['name' => 'Marsh Grove Bakery']);
        $project = $otherCompany->projects()->create(['name' => 'Not yours']);

        $this->actingAs($contact, 'client')
            ->postJson("/api/portal/projects/{$project->id}/tasks", ['title' => 'Should fail'])
            ->assertForbidden();
    }

    public function test_there_is_no_route_for_a_client_to_delete_a_task(): void
    {
        $contact = $this->portalContact();
        $project = $contact->company->projects()->create(['name' => 'Brand refresh']);
        $task = $project->tasks()->create(['title' => 'A task']);

        $this->actingAs($contact, 'client')
            ->deleteJson("/api/portal/tasks/{$task->id}")
            ->assertStatus(405);
    }

    public function test_a_client_can_send_and_reply_to_messages_on_their_project(): void
    {
        $contact = $this->portalContact();
        $project = $contact->company->projects()->create(['name' => 'Brand refresh']);

        $response = $this->actingAs($contact, 'client')->postJson("/api/portal/projects/{$project->id}/messages", [
            'subject' => 'Question about timeline',
            'body' => 'When will the first draft be ready?',
        ]);
        $response->assertCreated();

        $reply = $this->actingAs($contact, 'client')->postJson("/api/portal/projects/{$project->id}/messages", [
            'parent_id' => $response->json('id'),
            'body' => 'Following up on this',
        ]);
        $reply->assertCreated();

        $this->assertDatabaseHas('messages', ['id' => $response->json('id'), 'direction' => 'inbound', 'sender_contact_id' => $contact->id]);
    }

    public function test_only_accepted_proposals_are_visible_to_a_client(): void
    {
        $contact = $this->portalContact();
        $project = $contact->company->projects()->create(['name' => 'Brand refresh']);
        Proposal::create(['company_id' => $contact->company_id, 'project_id' => $project->id, 'title' => 'Draft one', 'body' => '<p>x</p>', 'status' => 'draft']);
        Proposal::create(['company_id' => $contact->company_id, 'project_id' => $project->id, 'title' => 'Accepted one', 'body' => '<p>x</p>', 'status' => 'accepted', 'accepted_at' => now()]);

        $response = $this->actingAs($contact, 'client')->get("/portal/projects/{$project->id}");

        $titles = collect($response->viewData('page')['props']['project']['proposals'])->pluck('title');
        $this->assertTrue($titles->contains('Accepted one'));
        $this->assertFalse($titles->contains('Draft one'));
    }

    public function test_only_sent_or_paid_invoices_are_visible_to_a_client(): void
    {
        $contact = $this->portalContact();
        $project = $contact->company->projects()->create(['name' => 'Brand refresh']);
        Invoice::create(['company_id' => $contact->company_id, 'project_id' => $project->id, 'status' => 'draft', 'surcharge' => false, 'issued_on' => now(), 'due_on' => now()->addDays(30)]);
        $paid = Invoice::create(['company_id' => $contact->company_id, 'project_id' => $project->id, 'status' => 'paid', 'surcharge' => false, 'issued_on' => now(), 'due_on' => now()->addDays(30)]);

        $response = $this->actingAs($contact, 'client')->get("/portal/projects/{$project->id}");

        $ids = collect($response->viewData('page')['props']['project']['invoices'])->pluck('id');
        $this->assertTrue($ids->contains($paid->id));
        $this->assertEquals(1, $ids->count());
    }

    public function test_a_client_sees_the_assigned_staff_roster(): void
    {
        $contact = $this->portalContact();
        $project = $contact->company->projects()->create(['name' => 'Brand refresh']);
        $manager = User::factory()->create(['name' => 'Bill Sattler']);
        $project->users()->attach($manager->id, ['assigned_at' => now()]);

        $response = $this->actingAs($contact, 'client')->get("/portal/projects/{$project->id}");

        $roster = collect($response->viewData('page')['props']['project']['active_users']);
        $this->assertTrue($roster->contains(fn ($u) => $u['name'] === 'Bill Sattler'));
        $this->assertArrayNotHasKey('email', $roster->first());
    }

    public function test_portal_routes_require_client_authentication(): void
    {
        $this->get('/portal')->assertRedirect('/portal/login');
    }

    public function test_staff_session_does_not_grant_portal_access(): void
    {
        $manager = User::factory()->create();

        $this->actingAs($manager)->get('/portal')->assertRedirect('/portal/login');
    }
}
