<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookkeepingSalesTaxTest extends TestCase
{
    use RefreshDatabase;

    private function taxedInvoice(): Invoice
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $invoice = $company->invoices()->create([
            'status' => 'sent', 'issued_on' => today(), 'due_on' => today()->addDays(30), 'payment_terms' => 'net_30',
            'tax_name' => 'Ohio sales tax', 'tax_rate' => 7.75,
        ]);
        $invoice->items()->create(['description' => 'Design', 'amount' => 1000, 'position' => 0]);
        $invoice->items()->create(['description' => 'Printing', 'amount' => 400, 'taxable' => true, 'position' => 1]);

        return $invoice->fresh();
    }

    public function test_paying_a_taxed_invoice_records_the_tax_inside_the_income(): void
    {
        $invoice = $this->taxedInvoice();
        $manager = User::factory()->create();

        $this->actingAs($manager)->postJson("/api/invoices/{$invoice->id}/mark-paid", ['method' => 'check'])->assertOk();

        $transaction = Transaction::where('invoice_id', $invoice->id)->sole();
        $this->assertEquals(1431.00, $transaction->amount); // 1400 + 7.75% of 400
        $this->assertEquals(31.00, $transaction->tax_amount);
    }

    public function test_the_summary_reports_sales_tax_apart_from_income_and_net(): void
    {
        $this->taxedInvoice()->recordPayment('check', 1431.00);
        Transaction::create(['type' => 'income', 'amount' => 500, 'occurred_on' => now()]);
        $month = now()->format('Y-m');

        $summary = $this->actingAs(User::factory()->create())->getJson("/api/bookkeeping/summary?month={$month}")->assertOk()->json();

        $this->assertEquals(1900.00, $summary['income']);
        $this->assertEquals(31.00, $summary['sales_tax']);
        $this->assertEquals(31.00, $summary['sales_tax_year']);
        $this->assertEquals(1900.00, $summary['net']);
    }

    public function test_manual_income_can_note_the_sales_tax_it_includes(): void
    {
        $manager = User::factory()->create();

        $this->actingAs($manager)->postJson('/api/transactions', [
            'amount' => 107.75, 'tax_amount' => 7.75, 'occurred_on' => today()->toDateString(),
        ])->assertCreated();

        $this->actingAs($manager)->postJson('/api/transactions', [
            'amount' => 10, 'tax_amount' => 20, 'occurred_on' => today()->toDateString(),
        ])->assertUnprocessable();

        $this->assertEquals(7.75, Transaction::sole()->tax_amount);
    }

    public function test_the_monthly_report_splits_gross_taxable_and_exempt_sales(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 15));
        $this->taxedInvoice()->recordPayment('check', 1431.00); // Sep: 1400 gross, 400 taxable, 31 tax
        Transaction::create(['type' => 'income', 'amount' => 500, 'occurred_on' => '2026-08-03']); // Aug: untaxed

        $this->actingAs(User::factory()->create())->get('/bookkeeping/sales-tax?year=2026')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Bookkeeping/SalesTax')
                ->has('report.months', 9) // January to this month
                ->where('report.months.7.gross_sales', 500)
                ->where('report.months.7.tax', 0)
                ->where('report.months.8.gross_sales', 1400)
                ->where('report.months.8.taxable_sales', 400)
                ->where('report.months.8.exempt_sales', 1000)
                ->where('report.months.8.tax', 31)
                ->has('report.months.8.payments', 1)
                ->where('report.totals.tax', 31)
                ->where('report.totals.gross_sales', 1900));
    }

    public function test_the_report_downloads_as_csv(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 15));
        $this->taxedInvoice()->recordPayment('check', 1431.00);

        $csv = $this->actingAs(User::factory()->create())->get('/bookkeeping/sales-tax.csv?year=2026')
            ->assertOk()
            ->assertDownload('sales-tax-2026.csv')
            ->streamedContent();

        $this->assertStringContainsString('Month,"Gross sales","Taxable sales","Exempt sales","Sales tax collected"', $csv);
        $this->assertStringContainsString('"September 2026",1400.00,400.00,1000.00,31.00', $csv);
        $this->assertStringContainsString('"Total 2026",1400.00,400.00,1000.00,31.00', $csv);
    }

    public function test_manual_income_with_tax_counts_its_taxable_sales_from_the_rate(): void
    {
        config(['invoicing.sales_tax.rate' => 7.75]);

        $this->actingAs(User::factory()->create())->postJson('/api/transactions', [
            'amount' => 107.75, 'tax_amount' => 7.75, 'occurred_on' => today()->toDateString(),
        ])->assertCreated();

        $this->assertEquals(100.00, Transaction::sole()->taxable_amount);
    }

    public function test_team_members_cannot_see_the_report(): void
    {
        $this->actingAs(User::factory()->teamMember()->create())->get('/bookkeeping/sales-tax')->assertForbidden();
    }
}
