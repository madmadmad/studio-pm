<?php

namespace Tests\Feature;

use App\Mail\ProposalEmail;
use App\Models\Company;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProposalSendTest extends TestCase
{
    use RefreshDatabase;

    private function makeProposal(bool $withContact = true): Proposal
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        if ($withContact) {
            $company->contacts()->create(['name' => 'Rosa Alder', 'email' => 'rosa@alderfinch.co', 'is_primary' => true]);
        }
        $project = $company->projects()->create(['name' => 'Brand refresh']);

        return $company->proposals()->create([
            'project_id' => $project->id, 'title' => 'Brand refresh', 'body' => '<p>Scope</p>', 'status' => 'draft',
        ]);
    }

    public function test_emailing_a_proposal_queues_it_to_the_primary_contact_and_marks_it_sent(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'studio@example.com']);
        $proposal = $this->makeProposal();

        $this->actingAs($user)->postJson("/api/proposals/{$proposal->id}/send", [
            'method' => 'email',
            'subject' => 'Your proposal',
            'message' => 'Here it is.',
            'cc' => ['ops@alderfinch.co'],
            'send_copy_to_self' => true,
        ])->assertOk()->assertJsonPath('status', 'sent');

        Mail::assertQueued(ProposalEmail::class, function (ProposalEmail $mail) {
            return $mail->hasTo('rosa@alderfinch.co')
                && $mail->emailSubject === 'Your proposal'
                && $mail->ccAddresses === ['ops@alderfinch.co', 'studio@example.com'];
        });
        $this->assertSame('estimated', $proposal->project->fresh()->status);
    }

    public function test_emailing_needs_a_contact_with_an_email(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $proposal = $this->makeProposal(withContact: false);

        $this->actingAs($user)->postJson("/api/proposals/{$proposal->id}/send", [
            'method' => 'email', 'subject' => 'Your proposal', 'message' => 'Here it is.',
        ])->assertStatus(422);

        Mail::assertNothingQueued();
        $this->assertSame('draft', $proposal->fresh()->status);
    }

    public function test_sharing_the_link_marks_it_sent_only_when_asked(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $proposal = $this->makeProposal();

        $this->actingAs($user)->postJson("/api/proposals/{$proposal->id}/send", ['method' => 'link'])->assertOk();
        $this->assertSame('draft', $proposal->fresh()->status);

        $this->actingAs($user)->postJson("/api/proposals/{$proposal->id}/send", ['method' => 'link', 'mark_as_sent' => true])->assertOk();
        $this->assertSame('sent', $proposal->fresh()->status);
        Mail::assertNothingQueued();
    }

    public function test_an_accepted_proposal_stays_accepted_when_its_link_is_shared(): void
    {
        $user = User::factory()->create();
        $proposal = $this->makeProposal();
        $proposal->update(['status' => 'accepted', 'accepted_at' => now()]);

        $this->actingAs($user)->postJson("/api/proposals/{$proposal->id}/send", ['method' => 'link', 'mark_as_sent' => true])->assertOk();

        $this->assertSame('accepted', $proposal->fresh()->status);
    }

    public function test_the_send_dialog_is_filled_in_from_the_templates(): void
    {
        $user = User::factory()->create();
        $proposal = $this->makeProposal();

        $response = $this->actingAs($user)->getJson("/api/proposals/{$proposal->id}/send-context")->assertOk();

        $response->assertJsonPath('to', 'rosa@alderfinch.co');
        $this->assertStringContainsString('Brand refresh', $response->json('subject'));
        $this->assertStringContainsString('Hi Rosa,', $response->json('message'));
        $this->assertStringEndsWith('/p/'.$proposal->accept_token, $response->json('public_url'));
    }

    public function test_the_email_preview_renders_the_message_and_link(): void
    {
        $user = User::factory()->create();
        $proposal = $this->makeProposal();

        $html = $this->actingAs($user)->postJson("/api/proposals/{$proposal->id}/email-preview", [
            'subject' => 'Your proposal', 'message' => 'Here it is.',
        ])->assertOk()->json('html');

        $this->assertStringContainsString('Here it is.', $html);
        $this->assertStringContainsString('/p/'.$proposal->accept_token, $html);
    }
}
