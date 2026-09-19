<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceItemDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_items_client_facing_details_can_be_set_on_creation(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);

        $response = $this->actingAs($user)->postJson("/api/companies/{$company->id}/invoices", [
            'items' => [
                ['description' => 'Design work', 'details' => 'Homepage, about, and contact page wireframes.', 'amount' => 1000],
            ],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('invoice_items', [
            'description' => 'Design work',
            'details' => 'Homepage, about, and contact page wireframes.',
        ]);
    }

    public function test_details_are_optional(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);

        $response = $this->actingAs($user)->postJson("/api/companies/{$company->id}/invoices", [
            'items' => [['description' => 'Design work', 'amount' => 1000]],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('invoice_items', ['description' => 'Design work', 'details' => null]);
    }

    public function test_details_can_be_changed_while_editing_a_draft(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $invoice = $company->invoices()->create([
            'status' => 'draft', 'surcharge' => false, 'issued_on' => now(), 'due_on' => now()->addDays(30),
        ]);
        $invoice->items()->create(['description' => 'Design work', 'amount' => 1000]);

        $response = $this->actingAs($user)->patchJson("/api/invoices/{$invoice->id}", [
            'items' => [['description' => 'Design work', 'details' => 'Revised scope notes.', 'amount' => 1000]],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'details' => 'Revised scope notes.']);
    }

    public function test_details_are_visible_on_the_public_invoice_page(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $invoice = $company->invoices()->create([
            'status' => 'sent', 'surcharge' => false, 'issued_on' => now(), 'due_on' => now()->addDays(30),
        ]);
        $invoice->items()->create(['description' => 'Design work', 'details' => 'Homepage and about page.', 'amount' => 1000]);

        $response = $this->get("/i/{$invoice->public_token}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('invoice.items.0.details', 'Homepage and about page.'));
    }
}
