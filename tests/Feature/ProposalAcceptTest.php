<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Proposal;
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
}
