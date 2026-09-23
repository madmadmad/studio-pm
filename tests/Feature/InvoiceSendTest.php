<?php

namespace Tests\Feature;

use App\Mail\InvoiceEmail;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceSend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InvoiceSendTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvoice(Company $company, array $overrides = []): Invoice
    {
        $invoice = $company->invoices()->create(array_merge([
            'status' => 'draft',
            'surcharge' => false,
            'issued_on' => now(),
            'due_on' => now()->addDays(14),
        ], $overrides));
        $invoice->items()->create(['description' => 'Brand refresh', 'amount' => 507.50]);

        return $invoice;
    }

    public function test_sending_an_invoice_emails_the_billing_contact_with_a_pdf_attached(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $contact = $company->contacts()->create(['name' => 'Accounts Payable', 'email' => 'ap@alderfinch.co', 'is_billing' => true]);
        $invoice = $this->makeInvoice($company, ['contact_id' => $contact->id]);

        $response = $this->actingAs($user)->postJson("/api/invoices/{$invoice->id}/send", [
            'method' => 'email',
            'subject' => "Invoice #{$invoice->invoice_number}",
            'message' => 'Please find attached the invoice.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'sent');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'sent']);
        $this->assertNotNull($invoice->fresh()->sent_at);
        $this->assertDatabaseHas('invoice_sends', [
            'invoice_id' => $invoice->id,
            'type' => InvoiceSend::TYPE_EMAIL,
            'status' => InvoiceSend::STATUS_SENT,
        ]);

        Mail::assertQueued(InvoiceEmail::class, function (InvoiceEmail $mail) use ($invoice, $contact) {
            $mail->assertTo($contact->email);
            $attachments = $mail->attachments();

            // Named after the firm (StudioProfile, default "Madhouse
            // Studio"), not the client's company name.
            return count($attachments) === 1
                && $attachments[0]->as === "Invoice-{$invoice->invoice_number}-MadhouseStudio.pdf";
        });
    }

    public function test_sending_an_invoice_with_no_specific_contact_falls_back_to_the_companys_billing_contact(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $company = Company::create(['name' => 'Spacely Sprockets']);
        $billingContact = $company->contacts()->create(['name' => 'Bud Bucks', 'email' => 'bud@spacely.co', 'is_billing' => true]);
        $invoice = $this->makeInvoice($company);

        $response = $this->actingAs($user)->postJson("/api/invoices/{$invoice->id}/send", [
            'method' => 'email',
            'subject' => 'Invoice',
            'message' => 'Body',
        ]);

        $response->assertOk();
        Mail::assertQueued(InvoiceEmail::class, fn (InvoiceEmail $mail) => $mail->hasTo($billingContact->email));
    }

    public function test_sending_an_invoice_with_no_billing_contact_falls_back_to_the_companys_primary_contact(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $company = Company::create(['name' => 'Thistle & Rye Events']);
        $primaryContact = $company->contacts()->create(['name' => 'Rye Thistle', 'email' => 'rye@thistleandrye.co', 'is_primary' => true]);
        $invoice = $this->makeInvoice($company);

        $response = $this->actingAs($user)->postJson("/api/invoices/{$invoice->id}/send", [
            'method' => 'email',
            'subject' => 'Invoice',
            'message' => 'Body',
        ]);

        $response->assertOk();
        Mail::assertQueued(InvoiceEmail::class, fn (InvoiceEmail $mail) => $mail->hasTo($primaryContact->email));
    }

    public function test_sending_an_invoice_without_a_billing_contact_email_is_rejected(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $company = Company::create(['name' => 'Marsh Grove Bakery']);
        $company->contacts()->create(['name' => 'No Email Contact', 'is_billing' => true]);
        $invoice = $this->makeInvoice($company);

        $response = $this->actingAs($user)->postJson("/api/invoices/{$invoice->id}/send", [
            'method' => 'email',
            'subject' => 'Invoice',
            'message' => 'Body',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'draft']);
        Mail::assertNothingSent();
    }

    public function test_sending_with_no_contact_at_all_is_rejected_for_both_methods(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $company = Company::create(['name' => 'No Contact Co']);
        $emailInvoice = $this->makeInvoice($company);
        $linkInvoice = $this->makeInvoice($company);

        $this->actingAs($user)->postJson("/api/invoices/{$emailInvoice->id}/send", ['method' => 'email', 'subject' => 'x', 'message' => 'y'])
            ->assertStatus(422);

        $this->actingAs($user)->postJson("/api/invoices/{$linkInvoice->id}/send", ['method' => 'link'])
            ->assertStatus(422);

        Mail::assertNothingSent();
    }

    public function test_a_zero_total_invoice_cannot_be_sent(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Zero Co']);
        $contact = $company->contacts()->create(['name' => 'A', 'email' => 'a@zero.co', 'is_primary' => true]);
        $invoice = $company->invoices()->create([
            'contact_id' => $contact->id, 'status' => 'draft', 'surcharge' => false,
            'issued_on' => now(), 'due_on' => now()->addDays(14),
        ]);

        $this->actingAs($user)->postJson("/api/invoices/{$invoice->id}/send", ['method' => 'link'])
            ->assertStatus(422);
    }

    public function test_a_paid_invoice_cannot_be_sent(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Paid Co']);
        $contact = $company->contacts()->create(['name' => 'A', 'email' => 'a@paid.co', 'is_primary' => true]);
        $invoice = $this->makeInvoice($company, ['contact_id' => $contact->id, 'status' => 'paid']);

        $this->actingAs($user)->postJson("/api/invoices/{$invoice->id}/send", ['method' => 'link'])
            ->assertStatus(422);
    }

    public function test_cc_addresses_and_a_copy_to_self_are_included(): void
    {
        Mail::fake();

        $user = User::factory()->create(['email' => 'me@studio.co']);
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $contact = $company->contacts()->create(['name' => 'AP', 'email' => 'ap@alderfinch.co', 'is_billing' => true]);
        $invoice = $this->makeInvoice($company, ['contact_id' => $contact->id]);

        $response = $this->actingAs($user)->postJson("/api/invoices/{$invoice->id}/send", [
            'method' => 'email',
            'subject' => 'Invoice',
            'message' => 'Body',
            'cc' => ['partner@alderfinch.co'],
            'send_copy_to_self' => true,
        ]);

        $response->assertOk();

        $send = InvoiceSend::first();
        $this->assertEqualsCanonicalizing(['partner@alderfinch.co', 'me@studio.co'], $send->cc);

        Mail::assertQueued(InvoiceEmail::class, fn (InvoiceEmail $mail) => $mail->hasCc('partner@alderfinch.co') && $mail->hasCc('me@studio.co'));
    }

    public function test_sending_via_url_marks_the_invoice_sent_by_default_and_records_history(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Link Co']);
        $contact = $company->contacts()->create(['name' => 'A', 'email' => 'a@link.co', 'is_primary' => true]);
        $invoice = $this->makeInvoice($company, ['contact_id' => $contact->id]);

        $response = $this->actingAs($user)->postJson("/api/invoices/{$invoice->id}/send", ['method' => 'link']);

        $response->assertOk();
        $response->assertJsonPath('status', 'sent');
        $this->assertDatabaseHas('invoice_sends', ['invoice_id' => $invoice->id, 'type' => InvoiceSend::TYPE_LINK]);
    }

    public function test_sending_via_url_without_mark_as_sent_leaves_the_invoice_as_draft(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Link Co']);
        $contact = $company->contacts()->create(['name' => 'A', 'email' => 'a@link.co', 'is_primary' => true]);
        $invoice = $this->makeInvoice($company, ['contact_id' => $contact->id]);

        $response = $this->actingAs($user)->postJson("/api/invoices/{$invoice->id}/send", [
            'method' => 'link',
            'mark_as_sent' => false,
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'draft');
    }

    public function test_regenerating_the_token_invalidates_the_old_link(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Token Co']);
        $invoice = $this->makeInvoice($company, ['status' => 'sent']);
        $oldToken = $invoice->public_token;

        $this->get("/i/{$oldToken}")->assertOk();

        $this->actingAs($user)->postJson("/api/invoices/{$invoice->id}/regenerate-token")->assertOk();

        $this->get("/i/{$oldToken}")->assertNotFound();
        $this->get("/i/{$invoice->fresh()->public_token}")->assertOk();
    }

    public function test_regenerating_the_token_of_one_invoice_never_affects_another(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Token Co']);
        $invoiceA = $this->makeInvoice($company, ['status' => 'sent']);
        $invoiceB = $this->makeInvoice($company, ['status' => 'sent']);
        $tokenB = $invoiceB->public_token;

        $this->actingAs($user)->postJson("/api/invoices/{$invoiceA->id}/regenerate-token")->assertOk();

        $this->get("/i/{$tokenB}")->assertOk();
        $this->assertSame($tokenB, $invoiceB->fresh()->public_token);
    }
}
