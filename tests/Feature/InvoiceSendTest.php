<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Notifications\InvoiceSent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InvoiceSendTest extends TestCase
{
    use RefreshDatabase;

    public function test_sending_an_invoice_emails_the_billing_contact_with_a_pdf_attached(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $contact = $company->contacts()->create(['name' => 'Accounts Payable', 'email' => 'ap@alderfinch.co', 'is_billing' => true]);

        $invoice = $company->invoices()->create([
            'contact_id' => $contact->id,
            'status' => 'draft',
            'surcharge' => false,
            'issued_on' => now(),
            'due_on' => now()->addDays(14),
        ]);
        $invoice->items()->create(['description' => 'Brand refresh', 'amount' => 507.50]);

        $response = $this->actingAs($user)->postJson("/api/invoices/{$invoice->id}/send");

        $response->assertOk();
        $response->assertJsonPath('status', 'sent');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'sent']);

        Notification::assertSentTo($contact, InvoiceSent::class, function (InvoiceSent $notification) use ($invoice) {
            $mail = $notification->toMail($notification->invoice->contact);

            return $mail->attachments === [] // attachData is stored separately
                && count($mail->rawAttachments) === 1
                && $mail->rawAttachments[0]['name'] === "invoice-{$invoice->invoice_number}.pdf";
        });
    }

    public function test_sending_an_invoice_with_no_specific_contact_falls_back_to_the_companys_billing_contact(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $company = Company::create(['name' => 'Spacely Sprockets']);
        $billingContact = $company->contacts()->create(['name' => 'Bud Bucks', 'email' => 'bud@spacely.co', 'is_billing' => true]);

        $invoice = $company->invoices()->create([
            'contact_id' => null,
            'status' => 'draft',
            'surcharge' => false,
            'issued_on' => now(),
            'due_on' => now()->addDays(14),
        ]);
        $invoice->items()->create(['description' => 'Sprocket consulting', 'amount' => 250]);

        $response = $this->actingAs($user)->postJson("/api/invoices/{$invoice->id}/send");

        $response->assertOk();
        $response->assertJsonPath('status', 'sent');

        Notification::assertSentTo($billingContact, InvoiceSent::class);
    }

    public function test_sending_an_invoice_with_no_billing_contact_falls_back_to_the_companys_primary_contact(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $company = Company::create(['name' => 'Thistle & Rye Events']);
        $primaryContact = $company->contacts()->create(['name' => 'Rye Thistle', 'email' => 'rye@thistleandrye.co', 'is_primary' => true]);

        $invoice = $company->invoices()->create([
            'contact_id' => null,
            'status' => 'draft',
            'surcharge' => false,
            'issued_on' => now(),
            'due_on' => now()->addDays(14),
        ]);
        $invoice->items()->create(['description' => 'Event coordination', 'amount' => 400]);

        $response = $this->actingAs($user)->postJson("/api/invoices/{$invoice->id}/send");

        $response->assertOk();
        $response->assertJsonPath('status', 'sent');

        Notification::assertSentTo($primaryContact, InvoiceSent::class);
    }

    public function test_sending_an_invoice_without_a_billing_contact_email_is_rejected(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $company = Company::create(['name' => 'Marsh Grove Bakery']);

        $invoice = $company->invoices()->create([
            'status' => 'draft',
            'surcharge' => false,
            'issued_on' => now(),
            'due_on' => now()->addDays(14),
        ]);
        $invoice->items()->create(['description' => 'Menu photography', 'amount' => 300]);

        $response = $this->actingAs($user)->postJson("/api/invoices/{$invoice->id}/send");

        $response->assertStatus(422);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'draft']);
        Notification::assertNothingSent();
    }
}
