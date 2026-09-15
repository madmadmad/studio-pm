<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceTotalsTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvoice(bool $surcharge): Invoice
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);

        return Invoice::create([
            'company_id' => $company->id,
            'status' => 'draft',
            'surcharge' => $surcharge,
            'issued_on' => now(),
            'due_on' => now()->addDays(14),
        ]);
    }

    public function test_subtotal_is_the_sum_of_item_amounts(): void
    {
        $invoice = $this->makeInvoice(surcharge: false);
        $invoice->items()->create(['description' => 'First item', 'amount' => 100]);
        $invoice->items()->create(['description' => 'Second item', 'amount' => 50]);

        $this->assertSame(150.0, $invoice->fresh('items')->subtotal());
    }

    public function test_surcharge_is_three_percent_of_subtotal_when_enabled(): void
    {
        $invoice = $this->makeInvoice(surcharge: true);
        $invoice->items()->create(['description' => 'Design work', 'amount' => 1000]);

        $invoice = $invoice->fresh('items');
        $this->assertSame(30.0, $invoice->surchargeAmount());
        $this->assertSame(1030.0, $invoice->total());
    }

    public function test_surcharge_is_zero_when_disabled(): void
    {
        $invoice = $this->makeInvoice(surcharge: false);
        $invoice->items()->create(['description' => 'Design work', 'amount' => 1000]);

        $invoice = $invoice->fresh('items');
        $this->assertSame(0.0, $invoice->surchargeAmount());
        $this->assertSame(1000.0, $invoice->total());
    }

    public function test_surcharge_rounds_to_the_nearest_cent(): void
    {
        $invoice = $this->makeInvoice(surcharge: true);
        // 507.50 * 0.03 = 15.225 -> rounds to 15.23
        $invoice->items()->create(['description' => 'Homepage wireframes', 'amount' => 507.50]);

        $invoice = $invoice->fresh('items');
        $this->assertSame(15.23, $invoice->surchargeAmount());
        $this->assertSame(522.73, $invoice->total());
    }
}
