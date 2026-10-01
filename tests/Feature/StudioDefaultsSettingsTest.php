<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\StudioProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudioDefaultsSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function saveSettings(User $manager, array $fields): void
    {
        $this->actingAs($manager)->patchJson('/api/studio-profile', ['name' => 'Madhouse Studio', ...$fields])->assertSuccessful();
    }

    public function test_the_studio_starts_from_the_config_defaults(): void
    {
        $profile = StudioProfile::current();

        $this->assertSame(config('proposals.default_disclaimer'), $profile->proposal_disclaimer);
        $this->assertSame(['name' => config('invoicing.sales_tax.name'), 'rate' => (float) config('invoicing.sales_tax.rate')], $profile->salesTax());
    }

    public function test_new_proposals_take_the_disclaimer_from_settings(): void
    {
        $manager = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $this->saveSettings($manager, ['proposal_disclaimer' => 'Our own scope note.']);

        $this->actingAs($manager)->postJson("/api/companies/{$company->id}/proposals", ['title' => 'Brand refresh', 'body' => '<p>Scope</p>', 'new_project_name' => 'Brand refresh'])
            ->assertCreated()
            ->assertJsonPath('disclaimer', 'Our own scope note.');

        $this->saveSettings($manager, ['proposal_disclaimer' => null]);
        $this->actingAs($manager)->postJson("/api/companies/{$company->id}/proposals", ['title' => 'Website', 'body' => '<p>Scope</p>', 'new_project_name' => 'Website'])
            ->assertCreated()
            ->assertJsonPath('disclaimer', null);
    }

    public function test_the_proposal_email_message_comes_from_settings(): void
    {
        $manager = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $proposal = $company->proposals()->create(['title' => 'Brand refresh', 'body' => '<p>Scope</p>']);
        $this->saveSettings($manager, ['proposal_email_message' => 'Hi :contact_first_name, here is :proposal_title from :firm_name.']);

        $this->actingAs($manager)->getJson("/api/proposals/{$proposal->id}/send-context")
            ->assertOk()
            ->assertJsonPath('message', 'Hi there, here is Brand refresh from Madhouse Studio.');

        // Blank falls back to the standard message.
        $this->saveSettings($manager, ['proposal_email_message' => null]);
        $this->assertStringStartsWith(
            'Thank you for considering Madhouse Studio',
            $this->actingAs($manager)->getJson("/api/proposals/{$proposal->id}/send-context")->json('message'),
        );
    }

    public function test_the_invoice_email_message_comes_from_settings(): void
    {
        $manager = User::factory()->create();
        $this->saveSettings($manager, ['invoice_email_message' => 'Invoice :invoice_number from :firm_name.']);
        $this->assertSame('Invoice :invoice_number from :firm_name.', Invoice::detailContext()['invoicingDefaults']['emailTemplate']);

        // Blank falls back to the standard message.
        $this->saveSettings($manager, ['invoice_email_message' => null]);
        $this->assertSame(config('invoicing.email_template'), Invoice::detailContext()['invoicingDefaults']['emailTemplate']);
    }

    public function test_invoices_take_the_sales_tax_from_settings(): void
    {
        $manager = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $this->saveSettings($manager, ['sales_tax_name' => 'Franklin County sales tax', 'sales_tax_rate' => 8]);

        $id = $this->actingAs($manager)->postJson("/api/companies/{$company->id}/invoices", [
            'tax' => true,
            'items' => [['description' => 'Printing', 'amount' => 100, 'taxable' => true]],
        ])->assertCreated()->json('id');

        $invoice = Invoice::find($id);
        $this->assertSame('Franklin County sales tax (8%)', $invoice->taxLabel());
        $this->assertSame(108.0, $invoice->total());
    }

    public function test_with_no_rate_there_is_no_tax_to_charge(): void
    {
        $manager = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $this->saveSettings($manager, ['sales_tax_name' => null, 'sales_tax_rate' => null]);

        $this->actingAs($manager)->get('/invoices')->assertInertia(fn ($page) => $page->where('salesTax', null));
        $this->actingAs($manager)->postJson("/api/companies/{$company->id}/invoices", [
            'tax' => true,
            'items' => [['description' => 'Printing', 'amount' => 100, 'taxable' => true]],
        ])->assertStatus(422);
    }
}
