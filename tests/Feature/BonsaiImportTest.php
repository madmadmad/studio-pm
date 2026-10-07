<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Transaction;
use App\Services\BonsaiImport\BonsaiImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// bonsai:import against a small sample of Bonsai's exports
// (tests/Fixtures/bonsai), one row for each rule in BonsaiRules.
class BonsaiImportTest extends TestCase
{
    use RefreshDatabase;

    private function import(bool $commit = true)
    {
        $dir = base_path('tests/Fixtures/bonsai');

        return app(BonsaiImport::class)->run("{$dir}/expenses.csv", "{$dir}/invoices.csv", "{$dir}/items.jsonl", '2025-01-01', $commit);
    }

    private function balance(string $key): int
    {
        return Account::forKey($key)->netDebitCents();
    }

    public function test_a_dry_run_reports_and_saves_nothing(): void
    {
        $report = $this->import(commit: false);

        $this->assertSame(1, $report->counts['Clients']);
        $this->assertSame(0, Company::count());
        $this->assertSame(0, Expense::count());
        $this->assertSame(0, JournalEntry::count());
        $this->assertDatabaseCount('import_records', 0);
    }

    public function test_expenses_follow_the_rules(): void
    {
        $report = $this->import();

        $this->assertSame(6, $report->counts['Expenses']);
        $this->assertCount(1, $report->skipped['Masked bank-feed copy of a Google Ads charge']);
        $this->assertCount(1, $report->skipped['Receipt-less copy of a charge with a receipt (bank feed)']);
        $this->assertCount(1, $report->skipped['Bonsai payment fee (posted with its payment)']);
        $this->assertCount(1, $report->skipped['Before 2025-01-01']);
        $this->assertCount(1, $report->skipped['Second copy of the ICHRA premium of 2025-02-21 (the bank paid it once)']);
        $this->assertNull(Expense::firstWhere('name', 'HNB-ECHO SPECIAL ACH'));
        $this->assertCount(1, $report->review['Wages to split out officer compensation']);

        $this->assertSame('capital_one_card', Expense::firstWhere('name', 'Spectrum')->paidFrom->system_key);
        $this->assertSame('checking', Expense::firstWhere('name', 'Columbia Gas')->paidFrom->system_key);
        $this->assertSame('payroll_clearing', Expense::firstWhere('name', 'Data Service Payroll')->paidFrom->system_key);
        $printing = Expense::firstWhere('name', 'GOTPRINT.COM');
        $this->assertSame('Printing', $printing->category->name);
        $this->assertSame('25.00', $printing->markup_percent);
        $this->assertStringContainsString('Bonsai receipt: https://app.hellobonsai.com/expenses/4/receipt', $printing->notes);
    }

    public function test_sales_tax_payments_and_draws_are_journal_entries_not_expenses(): void
    {
        $this->import();

        $this->assertNull(Expense::firstWhere('name', 'Ohio Sales Tax Liability'));
        $this->assertNull(Expense::firstWhere('name', 'Draw'));
        $this->assertSame(775, $this->balance('sales_tax_payable'));
        $this->assertSame(100000, $this->balance('shareholder_distributions'));
    }

    public function test_what_was_paid_from_a_personal_account_is_shareholder_capital(): void
    {
        $report = $this->import();

        $this->assertSame(1, $report->counts['Paid from a personal account (to Shareholder Capital)']);
        $this->assertNull(Expense::firstWhere('name', 'HNB-ECHO SPECIAL ACH'));
        $this->assertSame(-450000, $this->balance('shareholder_capital'));
        $this->assertSame(450000, Account::where('name', 'Health & Life Insurance')->sole()->netDebitCents());
    }

    public function test_invoices_come_in_with_their_lines_payments_and_attached_expenses(): void
    {
        $report = $this->import();

        $this->assertSame(2, $report->counts['Invoices, paid']);
        $this->assertSame(1, $report->counts['Invoices, open']);
        $this->assertCount(1, $report->skipped['Invoice scheduled in Bonsai']);

        $invoice = Invoice::where('invoice_number', 1001)->first();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(1010.0, $invoice->total());
        $this->assertSame(['Project Management', 'Google ADS111111111', 'GOTPRINT.COM'], $invoice->items->pluck('description')->all());
        $this->assertSame("Client calls\n2 hours at \$130.00/hour", $invoice->items[0]->details);
        $this->assertSame('billed_and_paid', Expense::firstWhere('name', 'Google ADS111111111')->billing_status);
        $this->assertSame($invoice->items[1]->id, Expense::firstWhere('name', 'Google ADS111111111')->invoice_item_id, 'the first row of the export is matched too');
        $this->assertSame($invoice->items[2]->id, Expense::firstWhere('name', 'GOTPRINT.COM')->invoice_item_id, 'rebuilt from the expense Bonsai attached');
        $this->assertEqualsWithDelta(10.0, (float) Payment::where('invoice_id', $invoice->id)->sole()->late_fee, 0.001);

        $income = Transaction::where('invoice_id', $invoice->id)->sole();
        $this->assertSame(Transaction::CLIENT_INVOICE, $income->category);
        $this->assertEqualsWithDelta(1010.0, (float) $income->amount, 0.001, 'what the income charts read');
        $this->assertSame(Payment::count(), Transaction::count());
    }

    public function test_a_reused_invoice_number_keeps_it_as_a_reference(): void
    {
        $this->import();

        $second = Invoice::where('legacy_number', '1002-1')->sole();
        $this->assertSame(1005, $second->invoice_number);
        $this->assertSame('sent', $second->status);
    }

    public function test_the_ledger_splits_revenue_by_line_with_fees_surcharges_and_late_fees(): void
    {
        $this->import();

        $this->assertSame(-26000, $this->balance('ad_management_revenue'), 'time on an ad invoice');
        $this->assertSame(-50000, $this->balance('client_media_revenue'));
        $this->assertSame(-25000, $this->balance('printing_revenue'));
        $this->assertSame(-10000, $this->balance('hosting_revenue'));
        $this->assertSame(-300, $this->balance('surcharge_income'));
        $this->assertSame(300, $this->balance('merchant_fees'));
        $this->assertSame(-1000, $this->balance('late_fee_income'));
        $this->assertSame(102000 + 10000 - 8000 - 775 - 100000, $this->balance('checking'));
        $this->assertSame(-500000, $this->balance('payroll_clearing'), 'until the bank pays it out');
        $this->assertSame(-(50000 + 12000 + 20000 + 53870), $this->balance('capital_one_card'));
    }

    public function test_running_again_skips_what_is_already_in(): void
    {
        $this->import();
        $counts = [Company::count(), Expense::count(), Invoice::count(), Payment::count(), JournalEntry::count()];

        $report = $this->import();

        $this->assertSame($counts, [Company::count(), Expense::count(), Invoice::count(), Payment::count(), JournalEntry::count()]);
        $this->assertSame(9, $report->counts['Expenses already imported (skipped)'], 'six expenses, the sales tax payment, the draw, the one paid personally');
        $this->assertSame(3, $report->counts['Invoices already imported (skipped)']);
    }

    public function test_opening_balances_and_card_payments_from_the_bank_check_against_its_balance(): void
    {
        $dir = base_path('tests/Fixtures/bonsai');
        $report = app(BonsaiImport::class)->run("{$dir}/expenses.csv", "{$dir}/invoices.csv", "{$dir}/items.jsonl", '2025-01-01', true, "{$dir}/bank.csv", ['checking' => '200000.00']);

        $this->assertSame(1, $report->counts['Opening balances']);
        $this->assertSame(1, $report->counts['Card payments from checking (transfers)']);
        $opening = JournalEntry::where('memo', 'like', 'Opening balance%')->sole();
        $this->assertSame('2024-12-31', $opening->entry_date->toDateString());
        $this->assertSame(-20000000, $this->balance('opening_balance_equity'));
        $this->assertSame(-(50000 + 12000 + 20000 + 53870) + 100000, $this->balance('capital_one_card'));
        $this->assertStringContainsString('2025-03-26  bank $1,234.56', implode("\n", $report->ledger));
    }

    public function test_the_statements_are_matched_and_an_expense_the_bank_paid_moves_to_checking(): void
    {
        $dir = base_path('tests/Fixtures/bonsai');
        $report = app(BonsaiImport::class)->run("{$dir}/expenses.csv", "{$dir}/invoices.csv", "{$dir}/items.jsonl", '2025-01-01', true, "{$dir}/checking.csv", [], ["{$dir}/card.csv"]);

        $this->assertSame('checking', Expense::firstWhere('name', 'Spectrum')->paidFrom->system_key, 'the bank paid it, though Bonsai put it on the card');
        $this->assertCount(1, $report->review['Moved to paid from checking (the bank paid it, not the card)']);
        $this->assertSame(0, JournalEntry::whereNotNull('reverses_entry_id')->count(), 'posted again from scratch, not reversed');
        $this->assertSame(-(50000 + 20000 + 53870) + 100000, $this->balance('capital_one_card'));

        $this->assertSame(3, $report->counts['Statement rows matched to the ledger (the card)']);
        $this->assertStringContainsString('AMEX', implode("\n", $report->review["On checking's statement, not in the ledger"]));
        $this->assertStringContainsString('NETLIFY', implode("\n", $report->review["On the card's statement, not in the ledger"]));
        $this->assertArrayNotHasKey('In the ledger for the card, not on its statement', $report->review, 'Adobe is after the statements end');
    }

    public function test_a_pay_run_is_settled_in_payroll_clearing_with_the_health_deduction(): void
    {
        $dir = base_path('tests/Fixtures/bonsai');
        $report = app(BonsaiImport::class)->run("{$dir}/expenses.csv", "{$dir}/invoices.csv", "{$dir}/items.jsonl", '2025-01-01', true, "{$dir}/checking.csv");

        $this->assertSame(2, $report->counts['Payroll debits from checking (to Payroll Clearing)'], 'Data Service and American Funds');
        $this->assertSame(['2025-03-12  Bonsai $5,000.00, bank $4,950.00, health deduction $50.00'], $report->review["Pay runs: employees' health deduction (Bonsai less the bank)"]);
        $this->assertSame(0, $this->balance('payroll_clearing'));
        $this->assertSame(450000 - 5000, Account::where('name', 'Health & Life Insurance')->sole()->netDebitCents());
    }

    public function test_the_command_needs_its_files(): void
    {
        $this->artisan('bonsai:import --expenses=/nope.csv')->assertFailed();
    }
}
