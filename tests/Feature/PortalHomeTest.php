<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The Client Hub's sections -- Projects (/portal), Proposals, Invoices,
// Contacts -- for the signed-in contact's company. Inertia sends every prop
// to the browser, so these check what's *sent*, not just what's shown.
class PortalHomeTest extends TestCase
{
    use RefreshDatabase;

    private function portalContact(array $attributes = []): Contact
    {
        $company = Company::create([
            'name' => 'Alder & Finch Design',
            'phone' => '419-555-0100',
            'address_line1' => '12 Main St.',
            'city' => 'Toledo',
            'state' => 'OH',
            'postal_code' => '43604',
            'default_payment_terms' => 'net_60',
        ]);
        $contact = $company->contacts()->create(array_merge(['name' => 'Rosa Alder', 'email' => 'rosa@alderfinch.co'], $attributes));
        $contact->forceFill(['portal_invited_at' => now()])->save();

        return $contact;
    }

    private function props(Contact $contact, string $url = '/portal'): array
    {
        $response = $this->actingAs($contact, 'client')->get($url);
        $response->assertOk();

        return $response->viewData('page')['props'];
    }

    private function invoice(Contact $contact, string $status): Invoice
    {
        $invoice = Invoice::create([
            'company_id' => $contact->company_id, 'status' => $status, 'surcharge' => false,
            'issued_on' => now(), 'due_on' => now()->addDays(30),
        ]);
        $invoice->items()->create(['description' => 'Design work', 'amount' => 1000]);

        return $invoice;
    }

    public function test_each_section_is_its_own_page(): void
    {
        $contact = $this->portalContact(['is_billing' => true]);

        foreach ([
            '/portal' => 'Portal/Projects/Index',
            '/portal/proposals' => 'Portal/Proposals/Index',
            '/portal/invoices' => 'Portal/Invoices/Index',
            '/portal/contacts' => 'Portal/Contacts/Index',
        ] as $url => $component) {
            $response = $this->actingAs($contact, 'client')->get($url);
            $response->assertOk();
            $this->assertSame($component, $response->viewData('page')['component']);
        }
    }

    public function test_the_contacts_page_carries_only_the_companys_name_and_contact_details(): void
    {
        $company = $this->props($this->portalContact(), '/portal/contacts')['company'];

        $this->assertSame('Alder & Finch Design', $company['name']);
        $this->assertEqualsCanonicalizing(['name', 'phone', 'address_line1', 'city', 'state', 'postal_code'], array_keys($company));
    }

    // The sidebar shows Invoices only when this is true.
    public function test_the_shared_contact_says_whether_invoices_are_visible(): void
    {
        $this->assertTrue($this->props($this->portalContact(['is_primary' => true]))['auth']['user']['can_view_invoices']);
        $this->assertFalse($this->props($this->portalContact())['auth']['user']['can_view_invoices']);
    }

    public function test_projects_are_the_active_ones_and_those_awaiting_a_proposal(): void
    {
        $contact = $this->portalContact();
        $active = $contact->company->projects()->create(['name' => 'Active', 'status' => 'active']);
        $awaiting = $contact->company->projects()->create(['name' => 'Awaiting', 'status' => 'estimated']);
        $awaiting->proposals()->create(['company_id' => $contact->company_id, 'title' => 'Phase 1', 'body' => '<p>x</p>', 'status' => 'sent']);
        $draftOnly = $contact->company->projects()->create(['name' => 'Draft only', 'status' => 'leads']);
        $draftOnly->proposals()->create(['company_id' => $contact->company_id, 'title' => 'Not sent', 'body' => '<p>x</p>', 'status' => 'draft']);
        $contact->company->projects()->create(['name' => 'Done', 'status' => 'completed']);

        $projects = collect($this->props($contact)['projects']);

        $this->assertEqualsCanonicalizing([$active->id, $awaiting->id], $projects->pluck('id')->all());
        $this->assertEqualsCanonicalizing(['id', 'name', 'status', 'tasks', 'unread_messages'], array_keys($projects->first()));
    }

    public function test_only_sent_and_accepted_proposals_are_listed(): void
    {
        $contact = $this->portalContact();
        foreach (['draft', 'sent', 'accepted'] as $status) {
            $contact->company->proposals()->create(['title' => "A {$status} one", 'body' => '<p>x</p>', 'status' => $status]);
        }

        $statuses = collect($this->props($contact, '/portal/proposals')['proposals'])->pluck('status');

        $this->assertEqualsCanonicalizing(['sent', 'accepted'], $statuses->all());
    }

    public function test_billing_and_primary_contacts_see_sent_and_paid_invoices(): void
    {
        foreach ([['is_billing' => true], ['is_primary' => true]] as $flags) {
            $contact = $this->portalContact($flags);
            $this->invoice($contact, 'draft');
            $sent = $this->invoice($contact, 'sent');
            $paid = $this->invoice($contact, 'paid');

            $props = $this->props($contact, '/portal/invoices');

            $this->assertEqualsCanonicalizing([$sent->id, $paid->id], collect($props['invoices'])->pluck('id')->all());
            $this->assertEquals(1000, $props['invoices'][0]['total']);
        }
    }

    public function test_other_contacts_cannot_open_invoices(): void
    {
        $contact = $this->portalContact();
        $this->invoice($contact, 'sent');

        $this->actingAs($contact, 'client')->get('/portal/invoices')->assertForbidden();
    }

    public function test_nothing_from_another_company_is_sent(): void
    {
        $contact = $this->portalContact(['is_billing' => true]);
        $other = Company::create(['name' => 'Marsh Grove Bakery']);
        $other->projects()->create(['name' => 'Not yours', 'status' => 'active']);
        $other->proposals()->create(['title' => 'Not yours', 'body' => '<p>x</p>', 'status' => 'sent']);
        $other->contacts()->create(['name' => 'Someone Else']);
        Invoice::create(['company_id' => $other->id, 'status' => 'sent', 'surcharge' => false, 'issued_on' => now(), 'due_on' => now()->addDays(30)]);

        $this->assertSame([], $this->props($contact)['projects']);
        $this->assertSame([], $this->props($contact, '/portal/proposals')['proposals']);
        $this->assertSame([], $this->props($contact, '/portal/invoices')['invoices']);
        $this->assertEquals(['Rosa Alder'], collect($this->props($contact, '/portal/contacts')['contacts'])->pluck('name')->all());
    }

    // Belt and braces over the explicit field lists: none of the staff-only
    // data the client page carries can appear anywhere in the payload.
    public function test_no_internal_fields_are_sent(): void
    {
        $contact = $this->portalContact(['is_billing' => true]);
        $project = $contact->company->projects()->create(['name' => 'Active', 'status' => 'active', 'budget' => 12345, 'po_number' => 'PO-9', 'description' => 'Internal notes']);
        $project->proposals()->create(['company_id' => $contact->company_id, 'title' => 'Phase 1', 'body' => '<p>x</p>', 'status' => 'sent']);
        $this->invoice($contact, 'sent');

        foreach (['/portal', '/portal/proposals', '/portal/invoices', '/portal/contacts'] as $url) {
            $json = json_encode($this->props($contact, $url));

            foreach (['budget', 'po_number', 'description', 'default_payment_terms', 'reminders_enabled', 'portal_invited_at', 'surcharge', 'stripe', 'invoice_sends', 'body', 'Internal notes', '12345'] as $needle) {
                $this->assertStringNotContainsString($needle, $json, "{$url} sent \"{$needle}\"");
            }
        }
    }
}
