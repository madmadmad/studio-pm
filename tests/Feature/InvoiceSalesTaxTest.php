<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceSalesTaxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['invoicing.sales_tax' => ['name' => 'Ohio sales tax', 'rate' => 7.25]]);
    }

    private function createInvoice(array $payload): array
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $manager = User::factory()->create();

        $invoice = $this->actingAs($manager)->postJson("/api/companies/{$company->id}/invoices", $payload)
            ->assertCreated()
            ->json();

        return [Invoice::find($invoice['id']), $manager];
    }

    public function test_an_invoice_charges_no_tax_unless_switched_on(): void
    {
        [$invoice] = $this->createInvoice(['items' => [['description' => 'Printing', 'amount' => 200, 'taxable' => true]]]);

        $this->assertNull($invoice->tax_rate);
        $this->assertSame(0.0, $invoice->taxAmount());
        $this->assertSame(200.0, $invoice->total());
    }

    public function test_tax_is_charged_only_on_taxable_lines_at_the_configured_rate(): void
    {
        [$invoice] = $this->createInvoice([
            'tax' => true,
            'items' => [
                ['description' => 'Design', 'amount' => 1000],
                ['description' => 'Printing', 'amount' => 250, 'taxable' => true],
            ],
        ]);

        $this->assertSame('Ohio sales tax', $invoice->tax_name);
        $this->assertSame('Ohio sales tax (7.25%)', $invoice->taxLabel());
        $this->assertSame(250.0, $invoice->taxableSubtotal());
        $this->assertSame(18.13, $invoice->taxAmount()); // 250 x 7.25% = 18.125
        $this->assertSame(1268.13, $invoice->total());
        $this->assertSame(1268.13, $invoice->remainingBalance());
    }

    public function test_editing_keeps_the_invoices_rate_even_after_the_configured_rate_changes(): void
    {
        [$invoice, $manager] = $this->createInvoice([
            'tax' => true,
            'items' => [['description' => 'Printing', 'amount' => 100, 'taxable' => true]],
        ]);

        config(['invoicing.sales_tax.rate' => 8.0]);

        $this->actingAs($manager)->patchJson("/api/invoices/{$invoice->id}", [
            'tax' => true,
            'items' => [['id' => $invoice->items->first()->id, 'description' => 'Printing', 'amount' => 200, 'taxable' => true]],
        ])->assertOk();

        $invoice = $invoice->fresh();
        $this->assertEquals(7.25, $invoice->tax_rate);
        $this->assertSame(14.5, $invoice->taxAmount());
    }

    public function test_switching_tax_off_clears_it(): void
    {
        [$invoice, $manager] = $this->createInvoice([
            'tax' => true,
            'items' => [['description' => 'Printing', 'amount' => 100, 'taxable' => true]],
        ]);

        $this->actingAs($manager)->patchJson("/api/invoices/{$invoice->id}", [
            'tax' => false,
            'items' => [['id' => $invoice->items->first()->id, 'description' => 'Printing', 'amount' => 100, 'taxable' => true]],
        ])->assertOk();

        $invoice = $invoice->fresh();
        $this->assertNull($invoice->tax_rate);
        $this->assertSame(100.0, $invoice->total());
    }

    public function test_recording_a_payment_pays_the_total_with_tax(): void
    {
        [$invoice, $manager] = $this->createInvoice([
            'tax' => true,
            'items' => [['description' => 'Printing', 'amount' => 100, 'taxable' => true]],
        ]);
        $invoice->update(['status' => 'sent']);

        $this->actingAs($manager)->postJson("/api/invoices/{$invoice->id}/mark-paid", ['method' => 'check'])->assertOk();

        $this->assertEquals(107.25, $invoice->fresh()->payments->first()->amount);
    }

    public function test_the_pdf_and_public_page_show_the_tax(): void
    {
        [$invoice] = $this->createInvoice([
            'tax' => true,
            'items' => [['description' => 'Printing', 'amount' => 100, 'taxable' => true]],
        ]);
        $invoice->update(['status' => 'sent']);

        $this->get("/i/{$invoice->public_token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('invoice.tax_rate', '7.250')->where('invoice.items.0.taxable', true));

        $html = view('pdfs.invoice', ['invoice' => $invoice->fresh()->load('items', 'payments', 'company', 'project', 'contact'), 'studio' => \App\Models\StudioProfile::current()])->render();
        $this->assertStringContainsString('Ohio sales tax (7.25%)', $html);
        $this->assertStringContainsString('$107.25', $html);
    }

    public function test_paying_online_by_card_is_always_offered(): void
    {
        [$invoice, $manager] = $this->createInvoice(['surcharge' => false, 'items' => [['description' => 'Design', 'amount' => 100]]]);
        $this->assertTrue($invoice->allowsCardPayment());

        $invoice->forceFill(['surcharge' => false])->save();
        $this->actingAs($manager)->patchJson("/api/invoices/{$invoice->id}", [
            'items' => [['id' => $invoice->items->first()->id, 'description' => 'Design', 'amount' => 100]],
        ])->assertOk();

        $this->assertTrue($invoice->fresh()->allowsCardPayment());
    }
}
