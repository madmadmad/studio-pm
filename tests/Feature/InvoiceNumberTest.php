<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_first_invoice_starts_at_1000(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);

        $response = $this->actingAs($user)->postJson("/api/companies/{$company->id}/invoices", [
            'items' => [['description' => 'Design work', 'amount' => 1000]],
        ]);

        $response->assertCreated();
        $this->assertSame(1000, $response->json('invoice_number'));
    }

    public function test_invoice_numbers_increment_sequentially(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);

        $first = $this->actingAs($user)->postJson("/api/companies/{$company->id}/invoices", [
            'items' => [['description' => 'Design work', 'amount' => 500]],
        ]);
        $second = $this->actingAs($user)->postJson("/api/companies/{$company->id}/invoices", [
            'items' => [['description' => 'More design work', 'amount' => 500]],
        ]);

        $this->assertSame(1000, $first->json('invoice_number'));
        $this->assertSame(1001, $second->json('invoice_number'));
    }

    public function test_invoice_numbers_are_visible_on_the_public_invoice_page(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $invoice = $company->invoices()->create([
            'status' => 'sent', 'surcharge' => false, 'issued_on' => now(), 'due_on' => now()->addDays(30),
        ]);
        $invoice->items()->create(['description' => 'Design work', 'amount' => 1000]);

        $response = $this->get("/i/{$invoice->public_token}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('invoice.invoice_number', 1000));
    }
}
