<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

// The reports built from the ledger, over two years of books:
//
//   2025: opening balances (checking $40,000, the card owing $3,000), a
//         $1,000 payment, a $120 internet bill on the card -- $880 profit.
//   2026: $500 of hosting billed against $200 of hosting cost, $100 of
//         wages from checking -- $200 profit -- and a $1,000 card payment.
class LedgerReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create();
        $ledger = app(Ledger::class);
        $internet = Account::where('name', 'Internet')->first();

        $ledger->post('2025-01-01', [
            ['account' => 'checking', 'debit_cents' => 4000000],
            ['account' => 'capital_one_card', 'credit_cents' => 300000],
            ['account' => 'opening_balance_equity', 'credit_cents' => 3700000],
        ], 'Opening balances');
        $ledger->post('2025-06-01', [['account' => 'checking', 'debit_cents' => 100000], ['account' => 'service_revenue', 'credit_cents' => 100000]], 'Payment');
        $ledger->post('2025-06-02', [['account' => $internet, 'debit_cents' => 12000], ['account' => 'capital_one_card', 'credit_cents' => 12000]], 'Spectrum');
        $ledger->post('2026-02-01', [['account' => 'checking', 'debit_cents' => 50000], ['account' => 'hosting_revenue', 'credit_cents' => 50000]], 'Hosting payment');
        $ledger->post('2026-02-01', [['account' => 'hosting_cost', 'debit_cents' => 20000], ['account' => 'capital_one_card', 'credit_cents' => 20000]], 'Linode');
        $ledger->post('2026-02-05', [['account' => 'wages', 'debit_cents' => 10000], ['account' => 'checking', 'credit_cents' => 10000]], 'Payroll');
        $ledger->post('2026-02-10', [['account' => 'capital_one_card', 'debit_cents' => 100000], ['account' => 'checking', 'credit_cents' => 100000]], 'Card payment');
    }

    public function test_the_general_ledger_runs_each_accounts_balance_through_the_dates(): void
    {
        $checking = Account::forKey('checking')->id;

        $this->actingAs($this->manager)->get("/bookkeeping/ledger/general-ledger?from=2026-01-01&to=2026-03-31&account={$checking}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Bookkeeping/GeneralLedger')
            ->has('report.accounts', 1)
            ->where('report.accounts.0.opening', 4100000)
            ->where('report.accounts.0.lines.0.balance', 4150000)
            ->where('report.accounts.0.lines.1.balance', 4140000)
            ->where('report.accounts.0.lines.2.memo', 'Card payment')
            ->where('report.accounts.0.lines.2.balance', 4040000)
            ->where('report.accounts.0.closing', 4040000));
    }

    public function test_income_and_expense_accounts_start_each_year_at_zero(): void
    {
        $this->actingAs($this->manager)->get('/bookkeeping/ledger/general-ledger?from=2026-01-01&to=2026-03-31')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.accounts', fn ($accounts) => collect($accounts)->doesntContain(fn ($a) => $a['account']['name'] === 'Design & Development Services'))
            ->where('report.accounts', fn ($accounts) => collect($accounts)->firstWhere('account.name', 'Capital One Card')['opening'] === 312000));
    }

    public function test_the_trial_balance_balances_with_earlier_profit_in_retained_earnings(): void
    {
        $this->actingAs($this->manager)->get('/bookkeeping/ledger/trial-balance?to=2026-03-31')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Bookkeeping/TrialBalance')
            ->where('report.balanced', true)
            ->where('report.prior_years_profit', 88000)
            ->where('report.totals.debit', 4040000 + 20000 + 10000)
            ->where('report.rows', fn ($rows) => collect($rows)->firstWhere('account.name', 'Retained Earnings')['credit'] === 88000
                && collect($rows)->firstWhere('account.name', 'Hosting')['credit'] === 50000
                && collect($rows)->doesntContain(fn ($r) => $r['account']['name'] === 'Internet')));
    }

    public function test_the_profit_and_loss_shows_gross_profit_then_net(): void
    {
        $this->actingAs($this->manager)->get('/bookkeeping/ledger/profit-loss?from=2026-01-01&to=2026-03-31')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Bookkeeping/LedgerProfitLoss')
            ->where('report.income.0.account.name', 'Hosting')
            ->where('report.cost_of_revenue.0.account.name', 'Hosting Cost')
            ->where('report.expenses.0.account.name', 'Wages')
            ->where('report.totals', ['income' => 50000, 'cost_of_revenue' => 20000, 'gross_profit' => 30000, 'expenses' => 10000, 'net' => 20000]));
    }

    public function test_the_balance_sheet_balances_with_this_years_earnings_and_last_years(): void
    {
        $this->actingAs($this->manager)->get('/bookkeeping/ledger/balance-sheet?to=2026-03-31')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Bookkeeping/BalanceSheet')
            ->where('report.balanced', true)
            ->where('report.totals.assets', 4040000)
            ->where('report.totals.liabilities', 232000)
            ->where('report.totals.equity', 3808000)
            ->where('report.equity', fn ($equity) => collect($equity)->firstWhere('account.name', 'Retained Earnings')['amount'] === 88000
                && collect($equity)->firstWhere('account.name', 'Current year earnings')['amount'] === 20000));

        // At the end of 2025, its profit is still this year's.
        $this->actingAs($this->manager)->get('/bookkeeping/ledger/balance-sheet?to=2025-12-31')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.balanced', true)
            ->where('report.equity', fn ($equity) => collect($equity)->firstWhere('account.name', 'Current year earnings')['amount'] === 88000
                && collect($equity)->doesntContain(fn ($e) => $e['account']['name'] === 'Retained Earnings')));
    }

    public function test_each_report_downloads_as_csv(): void
    {
        $this->actingAs($this->manager);

        $csv = $this->get('/bookkeeping/ledger/profit-loss.csv?from=2026-01-01&to=2026-03-31')->assertDownload('profit-and-loss-2026-01-01-to-2026-03-31.csv')->streamedContent();
        $this->assertStringContainsString('"Gross profit",300.00', $csv);
        $this->assertStringContainsString('"Net profit",200.00', $csv);

        $csv = $this->get('/bookkeeping/ledger/trial-balance.csv?to=2026-03-31')->assertDownload('trial-balance-2026-03-31.csv')->streamedContent();
        $this->assertStringContainsString(',Total,40700.00,40700.00', $csv);

        $csv = $this->get('/bookkeeping/ledger/balance-sheet.csv?to=2026-03-31')->assertDownload('balance-sheet-2026-03-31.csv')->streamedContent();
        $this->assertStringContainsString('"Total liabilities and equity",40400.00', $csv);

        $csv = $this->get('/bookkeeping/ledger/general-ledger.csv?from=2026-01-01&to=2026-03-31')->assertDownload('general-ledger-2026-01-01-to-2026-03-31.csv')->streamedContent();
        $this->assertStringContainsString('"Card payment"', $csv);
    }

    public function test_dates_given_backwards_are_put_in_order(): void
    {
        $this->actingAs($this->manager)->get('/bookkeeping/ledger/profit-loss?from=2026-03-31&to=2026-01-01')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.from', '2026-01-01')
            ->where('report.to', '2026-03-31'));
    }

    public function test_the_reports_need_bookkeeping(): void
    {
        $member = User::factory()->teamMember()->create();

        foreach (['general-ledger', 'trial-balance', 'profit-loss', 'balance-sheet', 'balance-sheet.csv'] as $report) {
            $this->actingAs($member)->get("/bookkeeping/ledger/{$report}")->assertForbidden();
        }
    }
}
