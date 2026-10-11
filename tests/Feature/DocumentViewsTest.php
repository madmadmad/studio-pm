<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Proposal;
use App\Models\User;
use App\Notifications\DocumentOpened;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

// The client opening a sent invoice's or proposal's link: counted when
// its page reports in (not for signed-in staff, once per half hour per
// browser), with the first open telling whoever sent it -- unless they've
// turned that off.
class DocumentViewsTest extends TestCase
{
    use RefreshDatabase;

    private User $sam;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sam = User::factory()->teamMember()->create(['permissions' => ['invoices', 'proposals']]);
        $this->company = Company::create(['name' => 'Acme']);
    }

    private function sentInvoice(?User $by = null): Invoice
    {
        $invoice = $this->company->invoices()->create(['status' => 'sent', 'issued_on' => '2026-10-01', 'due_on' => '2026-10-31', 'sent_at' => now()]);
        $invoice->invoiceSends()->create(['type' => 'email', 'status' => 'sent', 'sent_at' => now(), 'sent_by_user_id' => ($by ?? $this->sam)->id, 'recipients' => ['client@acme.example']]);

        return $invoice;
    }

    private function sentProposal(?User $by = null): Proposal
    {
        $proposal = Proposal::create(['company_id' => $this->company->id, 'title' => 'Rebrand', 'body' => '<p>Scope</p>', 'status' => 'sent', 'sent_at' => now()]);
        $proposal->forceFill(['sent_by_user_id' => ($by ?? $this->sam)->id])->save();

        return $proposal;
    }

    public function test_opening_an_invoice_is_counted_and_tells_the_sender_once(): void
    {
        Notification::fake();
        $invoice = $this->sentInvoice();

        $this->post("/i/{$invoice->public_token}/viewed")->assertNoContent();

        $invoice->refresh();
        $this->assertNotNull($invoice->first_viewed_at);
        $this->assertSame(1, $invoice->view_count);
        Notification::assertSentTo($this->sam, DocumentOpened::class, fn ($n) => $n->toArray($this->sam)['message'] === "Acme opened invoice #{$invoice->invoice_number}"
            && $n->toArray($this->sam)['url'] === "/invoices/{$invoice->id}");

        // Again from the same browser soon after: not another view.
        $this->post("/i/{$invoice->public_token}/viewed");
        $this->assertSame(1, $invoice->fresh()->view_count);

        // Later, or from another browser: another view, but no second alert.
        $this->travel(31)->minutes();
        $this->post("/i/{$invoice->public_token}/viewed");
        $this->assertSame(2, $invoice->fresh()->view_count);
        Notification::assertSentToTimes($this->sam, DocumentOpened::class, 1);
    }

    public function test_opening_a_proposal_tells_whoever_sent_it(): void
    {
        Notification::fake();
        $alex = User::factory()->teamMember()->create(['permissions' => ['proposals']]);
        $proposal = $this->sentProposal($alex);

        $this->post("/p/{$proposal->accept_token}/viewed")->assertNoContent();

        $this->assertSame(1, $proposal->fresh()->view_count);
        Notification::assertSentTo($alex, DocumentOpened::class);
        Notification::assertNotSentTo($this->sam, DocumentOpened::class);
    }

    public function test_sending_a_proposal_records_who_sent_it(): void
    {
        $proposal = Proposal::create(['company_id' => $this->company->id, 'title' => 'Rebrand', 'body' => '<p>Scope</p>', 'status' => 'draft']);

        $this->actingAs($this->sam)->postJson("/api/proposals/{$proposal->id}/send", ['method' => 'link', 'mark_as_sent' => true])->assertOk();

        $this->assertSame($this->sam->id, $proposal->fresh()->sent_by_user_id);
    }

    public function test_signed_in_staff_opening_the_link_is_not_counted(): void
    {
        Notification::fake();
        $invoice = $this->sentInvoice();

        $this->actingAs($this->sam, 'web')->post("/i/{$invoice->public_token}/viewed")->assertNoContent();

        $this->assertNull($invoice->fresh()->first_viewed_at);
        $this->assertSame(0, $invoice->fresh()->view_count);
        Notification::assertNothingSent();
    }

    public function test_the_alert_can_be_turned_off(): void
    {
        Notification::fake();
        $this->actingAs($this->sam)->patchJson('/api/profile/alerts', ['notify_on_document_open' => false])
            ->assertOk()
            ->assertJsonPath('notify_on_document_open', false);
        auth()->forgetGuards();

        $invoice = $this->sentInvoice();
        $this->post("/i/{$invoice->public_token}/viewed");

        $this->assertSame(1, $invoice->fresh()->view_count); // still counted
        Notification::assertNothingSent();
    }

    public function test_with_no_known_sender_everyone_on_invoices_hears(): void
    {
        Notification::fake();
        $admin = User::factory()->create();
        $nobody = User::factory()->teamMember()->create(['permissions' => ['projects']]);
        $invoice = $this->company->invoices()->create(['status' => 'sent', 'issued_on' => '2026-10-01', 'due_on' => '2026-10-31', 'sent_at' => now()]);

        $this->post("/i/{$invoice->public_token}/viewed");

        Notification::assertSentTo([$this->sam, $admin], DocumentOpened::class);
        Notification::assertNotSentTo($nobody, DocumentOpened::class);
    }

    public function test_an_unknown_link_is_not_found(): void
    {
        $this->post('/i/not-a-token/viewed')->assertNotFound();
        $this->post('/p/not-a-token/viewed')->assertNotFound();
    }

    public function test_the_client_never_sees_the_counts(): void
    {
        $invoice = $this->sentInvoice();
        $invoice->forceFill(['view_count' => 3, 'first_viewed_at' => now()])->save();
        $proposal = $this->sentProposal();

        $this->get("/i/{$invoice->public_token}")->assertInertia(fn ($page) => $page->missing('invoice.view_count')->missing('invoice.first_viewed_at'));
        $this->get("/p/{$proposal->accept_token}")->assertInertia(fn ($page) => $page->missing('proposal.view_count')->missing('proposal.sent_by_user_id'));
    }

    public function test_staff_see_when_it_was_opened(): void
    {
        $invoice = $this->sentInvoice();
        $this->post("/i/{$invoice->public_token}/viewed");

        $this->actingAs($this->sam)->getJson("/api/invoices/{$invoice->id}")
            ->assertJsonPath('invoice.view_count', 1)
            ->assertJsonPath('invoice.first_viewed_at', fn ($at) => $at !== null);
    }
}
