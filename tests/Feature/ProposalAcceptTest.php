<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Proposal;
use App\Models\User;
use App\Notifications\ProposalAccepted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProposalAcceptTest extends TestCase
{
    use RefreshDatabase;

    private function makeProposal(): Proposal
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);

        return $company->proposals()->create([
            'title' => 'Brand refresh',
            'body' => '<p>Scope of work</p>',
            'estimate_amount' => 2000,
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    public function test_accepting_a_proposal_sets_status_and_timestamp(): void
    {
        $proposal = $this->makeProposal();

        $response = $this->postJson("/api/proposals/{$proposal->accept_token}/accept");

        $response->assertOk();
        $proposal->refresh();
        $this->assertSame('accepted', $proposal->status);
        $this->assertNotNull($proposal->accepted_at);
    }

    public function test_accepting_a_proposal_notifies_the_firm_by_mail(): void
    {
        Notification::fake();

        $proposal = $this->makeProposal();
        $this->postJson("/api/proposals/{$proposal->accept_token}/accept")->assertOk();

        Notification::assertSentOnDemand(ProposalAccepted::class);
    }

    public function test_accepting_an_already_accepted_proposal_does_not_send_a_duplicate_notification(): void
    {
        Notification::fake();

        $proposal = $this->makeProposal();
        $this->postJson("/api/proposals/{$proposal->accept_token}/accept")->assertOk();
        $this->postJson("/api/proposals/{$proposal->accept_token}/accept")->assertOk();

        Notification::assertSentOnDemandTimes(ProposalAccepted::class, 1);
    }

    public function test_unknown_token_returns_not_found(): void
    {
        $this->postJson('/api/proposals/does-not-exist/accept')->assertNotFound();
    }

    public function test_an_authenticated_user_can_unaccept_a_proposal(): void
    {
        $user = User::factory()->create();
        $proposal = $this->makeProposal();
        $this->postJson("/api/proposals/{$proposal->accept_token}/accept")->assertOk();

        $response = $this->actingAs($user)->postJson("/api/proposals/{$proposal->id}/unaccept");

        $response->assertOk();
        $proposal->refresh();
        $this->assertSame('sent', $proposal->status);
        $this->assertNull($proposal->accepted_at);
    }

    public function test_unaccepting_requires_authentication(): void
    {
        $proposal = $this->makeProposal();

        $this->postJson("/api/proposals/{$proposal->id}/unaccept")->assertUnauthorized();
    }

    public function test_accepting_a_proposal_adds_its_total_to_the_projects_budget(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);
        $proposal = $company->proposals()->create([
            'project_id' => $project->id,
            'title' => 'Brand refresh',
            'body' => '<p>Scope</p>',
            'estimate_amount' => 2000,
            'status' => 'sent',
        ]);

        $this->postJson("/api/proposals/{$proposal->accept_token}/accept")->assertOk();

        $this->assertEquals(2000, $project->fresh()->budget);
    }

    public function test_a_second_accepted_proposal_adds_to_the_existing_budget(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);
        $first = $company->proposals()->create([
            'project_id' => $project->id, 'title' => 'Phase 1', 'body' => '<p>Scope</p>', 'estimate_amount' => 2000, 'status' => 'sent',
        ]);
        $second = $company->proposals()->create([
            'project_id' => $project->id, 'title' => 'Phase 2', 'body' => '<p>Scope</p>', 'estimate_amount' => 1500, 'status' => 'sent',
        ]);

        $this->postJson("/api/proposals/{$first->accept_token}/accept")->assertOk();
        $this->postJson("/api/proposals/{$second->accept_token}/accept")->assertOk();

        $this->assertEquals(3500, $project->fresh()->budget);
    }

    public function test_unaccepting_a_proposal_removes_its_total_from_the_budget(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);
        $proposal = $company->proposals()->create([
            'project_id' => $project->id, 'title' => 'Brand refresh', 'body' => '<p>Scope</p>', 'estimate_amount' => 2000, 'status' => 'sent',
        ]);
        $this->postJson("/api/proposals/{$proposal->accept_token}/accept")->assertOk();

        $this->actingAs($user)->postJson("/api/proposals/{$proposal->id}/unaccept")->assertOk();

        $this->assertEquals(0, $project->fresh()->budget);
    }

    public function test_accepting_a_proposal_with_no_project_does_not_error(): void
    {
        $proposal = $this->makeProposal();

        $this->postJson("/api/proposals/{$proposal->accept_token}/accept")->assertOk();
    }
}
