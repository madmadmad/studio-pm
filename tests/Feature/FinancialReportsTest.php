<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\InvoiceCategory;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialReportsTest extends TestCase
{
    use RefreshDatabase;

    private function setUpYear(): array
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $hosting = InvoiceCategory::firstOrCreate(['name' => 'Hosting']);
        $design = $company->invoices()->create(['status' => 'sent', 'issued_on' => '2026-03-01', 'due_on' => '2026-03-31', 'payment_terms' => 'net_30']);
        $design->items()->create(['description' => 'Design', 'amount' => 1000, 'position' => 0]);
        $design->recordPayment('check', 1000);
        Transaction::where('invoice_id', $design->id)->update(['occurred_on' => '2026-03-20']);
        $site = $company->invoices()->create(['category_id' => $hosting->id, 'status' => 'sent', 'issued_on' => '2026-04-01', 'due_on' => '2026-05-01', 'payment_terms' => 'net_30', 'tax_name' => 'Ohio sales tax', 'tax_rate' => 10]);
        $site->items()->create(['description' => 'Hosting', 'amount' => 200, 'taxable' => true, 'position' => 0]);
        $site->recordPayment('check', 220);
        Transaction::where('invoice_id', $site->id)->update(['occurred_on' => '2026-04-15']);
        Expense::create(['name' => 'Linode', 'amount' => 60, 'date' => '2026-04-01', 'category_id' => ExpenseCategory::where('name', 'Hosting')->value('id')]);
        Expense::create(['name' => 'Coffee', 'amount' => 15, 'date' => '2026-04-02']);

        return [$company, User::factory()->create()];
    }

    public function test_the_profit_and_loss_groups_income_and_expenses_and_leaves_out_sales_tax(): void
    {
        [, $manager] = $this->setUpYear();

        $this->actingAs($manager)->get('/bookkeeping/profit-loss?year=2026')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Bookkeeping/ProfitLoss')
            ->where('report.income.0.name', 'Project work')
            ->where('report.income.0.amount', 1000)
            ->where('report.income.1.name', 'Hosting')
            ->where('report.income.1.amount', 200)
            ->where('report.expenses.0.name', 'Hosting')
            ->where('report.expenses.1.name', 'Uncategorized')
            ->where('report.totals.income', 1200)
            ->where('report.totals.expenses', 75)
            ->where('report.totals.net', 1125)
            ->where('report.sales_tax_collected', 20));

        $csv = $this->get('/bookkeeping/profit-loss.csv?year=2026')->assertDownload('profit-and-loss-2026.csv')->streamedContent();
        $this->assertStringContainsString('"Net profit",1125.00', $csv);
    }

    public function test_invoices_and_expenses_download_for_the_year(): void
    {
        [, $manager] = $this->setUpYear();

        $invoices = $this->actingAs($manager)->get('/bookkeeping/invoices.csv?year=2026')->assertDownload('invoices-2026.csv')->streamedContent();
        $this->assertStringContainsString('Invoice,Client,Project,Category,Issued,Due,Status,Subtotal,"Sales tax",Total,Paid,Outstanding,"Paid on"', $invoices);
        $this->assertStringContainsString('Hosting,2026-04-01,2026-05-01,Paid,200.00,20.00,220.00,220.00,0.00', $invoices);

        $expenses = $this->get('/bookkeeping/expenses.csv?year=2026')->assertDownload('expenses-2026.csv')->streamedContent();
        $this->assertStringContainsString('2026-04-01,Linode,Hosting,,60.00,No,unbilled', $expenses);
        $this->assertSame(3, substr_count(trim($expenses), "\n") + 1); // header + 2 expenses
    }

    public function test_the_bookkeeping_page_offers_the_years_to_report_on(): void
    {
        $this->travelTo('2026-10-01');
        [, $manager] = $this->setUpYear();

        $this->actingAs($manager)->get('/bookkeeping')->assertInertia(fn ($page) => $page->where('reportYears.0', 2026));
    }
}
