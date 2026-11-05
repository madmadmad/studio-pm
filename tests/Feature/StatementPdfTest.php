<?php

namespace Tests\Feature;

use App\Exceptions\LedgerException;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Reconciliation\Statements\BankStatement;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Reconciling from the bank statement's PDF (Statements\WaterfordStatement):
// read, checked against the statement's own totals, previewed, then
// started -- its rows ticking their matches -- with the PDF kept.
class StatementPdfTest extends TestCase
{
    use RefreshDatabase;

    // A made-up September statement, laid out as Waterford's PDF reads:
    // the date run into the description, a long one wrapped with its
    // amount a line down, the page footer and the back page's small print
    // between the credits and the debits, two checks to a line (one after
    // a skipped number). Starts at $0.00, ends at $425.00.
    private const STATEMENT = <<<'TXT'
        Waterford Bank, N.A.
        Summary of Accounts
        Waterford Business Analysis Checking	XXXXXXXX1234	$425.00
        Waterford Business Analysis Checking  -  XXXXXXXX1234
        Account Summary
        Date Description	Amount
        09/01/2026 Beginning Balance	$0.00
        2 Credit(s) This Period	$600.00
        3 Debit(s) This Period	$175.00
        09/30/2026 Ending Balance	$425.00
        Credits
        Date Description	Amount
        09/05/2026CLIENT A PAYMENT 0000123	$500.00
        09/12/2026Cashflow36 GROSSE POINTE PU PUBLIC LIBRARY Cashflow360
        016SYMTTU48
        $100.00
        Statement Ending 09/30/2026
          Page 1 of 3
        845041A660DD6441B4696815CE002166	20260930 Checking Account Statements
        IN CASE OF ERRORS OR QUESTIONS ABOUT YOUR STATEMENT
        3. Tell us the dollar amount of the suspected error.
        Statement Ending 09/30/2026	Page 2 of 3
        Waterford Business Analysis Checking  -  XXXXXXXX1234 (continued)
        Debits
        Date Description	Amount
        09/10/2026Rent	$100.00
        Checks Cleared
        Check Nbr Date Amount
        8670 09/23/2026 $50.00 8672* 09/24/2026 $25.00
        * Indicates skipped check number
        Daily Balances
        Date	Amount
        09/05/2026	$500.00
        09/10/2026	$400.00
        09/12/2026	$500.00
        09/23/2026	$450.00
        09/24/2026	$425.00
        Statement Ending 09/30/2026	Page 3 of 3
        TXT;

    // A made-up Capital One cycle from December into January, laid out as
    // its PDF reads: the account summary, two cardholders (the second
    // with a credit), a charge made before the cycle began, the page
    // header repeated between them, and interest. Owes $1,000.00 before,
    // $195.00 after: - $1,000.00 paid - $5.00 back + $185.00 + $15.00.
    private const CARD = <<<'TXT'
        Account Summary
        Previous Balance	$1,000.00
        Payments	- $1,000.00
        Other Credits	- $5.00
        Transactions	+ $185.00
        Cash Advances	+ $0.00
        Fees Charged	+ $0.00
        Interest Charged	+ $15.00
        New Balance	= $195.00
        Pay or manage your account at capitalone.com
        Spark Cash card | World Elite Mastercard for Business ending in 4321
        Dec 03, 2026 - Jan 02, 2027   |  31 days in Billing Cycle
        Transactions
        JANE DOE #4321: Payments, Credits and Adjustments
        Trans Date Post Date Description	Amount
        Dec 20 Dec 20 CAPITAL ONE ONLINE PYMT	- $1,000.00
        JANE DOE #4321: Transactions
        Trans Date Post Date Description	Amount
        Dec 1 Dec 3 Amazon web services SEATTLE WA	$100.00
        Jan 1 Jan 2 Google Workspace_madma Mountain View CA	$50.00
        JANE DOE #4321: Total Transactions	$150.00
        Spark Cash card | World Elite Mastercard for Business ending in 4321
        Dec 03, 2026 - Jan 02, 2027   |  31 days in Billing Cycle
        Page 3 of 6
        Transactions (Continued)
        JOHN ROE #8765: Payments, Credits and Adjustments
        Trans Date Post Date Description	Amount
        Dec 14 Dec 15 MOO PRINT WILMINGTON DE	- $5.00
        JOHN ROE #8765: Transactions
        Trans Date Post Date Description	Amount
        Dec 12 Dec 13 MOO PRINT WILMINGTON DE	$35.00
        JOHN ROE #8765: Total Transactions	$35.00
        Total Transactions for This Period	$185.00
        Fees
        Trans Date Post Date Description	Amount
        Total Fees for This Period	$0.00
        Interest Charged
        Interest Charge on Purchases	$15.00
        Total Interest for This Period	$15.00
        TXT;

    private User $admin;

    private Account $checking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->checking = Account::forKey('checking');
    }

    private function pdf(string $text = self::STATEMENT): UploadedFile
    {
        $html = '<pre style="font-family: DejaVu Sans">'.e($text).'</pre>';

        return UploadedFile::fake()->createWithContent('statement.pdf', Pdf::loadHTML($html)->output());
    }

    public function test_a_statement_is_read_wrapped_lines_and_checks_included(): void
    {
        $s = BankStatement::fromText(self::STATEMENT);

        $this->assertSame(['Waterford', 'checking', '1234', '2026-09-01', '2026-09-30', 0, 42500],
            [$s->bank, $s->accountKey, $s->accountLast4, $s->periodStart, $s->statementDate, $s->beginningCents, $s->endingCents]);
        $this->assertSame([
            ['date' => '2026-09-05', 'cents' => 50000, 'description' => 'CLIENT A PAYMENT 0000123'],
            ['date' => '2026-09-10', 'cents' => -10000, 'description' => 'Rent'],
            ['date' => '2026-09-12', 'cents' => 10000, 'description' => 'Cashflow36 GROSSE POINTE PU PUBLIC LIBRARY Cashflow360 016SYMTTU48'],
            ['date' => '2026-09-23', 'cents' => -5000, 'description' => 'Check 8670'],
            ['date' => '2026-09-24', 'cents' => -2500, 'description' => 'Check 8672'],
        ], $s->rows);
        $this->assertSame(['2026-09-05' => 50000, '2026-09-10' => 40000, '2026-09-12' => 50000, '2026-09-23' => 45000, '2026-09-24' => 42500], $s->dailyBalances);
    }

    public function test_one_that_doesnt_add_up_or_isnt_a_statement_is_refused(): void
    {
        // A credit lost in the reading: refused, not half-reconciled.
        $short = str_replace("09/05/2026CLIENT A PAYMENT 0000123\t$500.00\n", '', self::STATEMENT);
        $this->assertRefused($short, 'Read 1 credits for $100.00, but the statement says 2 for $600.00.');

        $this->assertRefused("Pay or manage your account at capitalone.com\nAug 03, 2026 - Sep 02, 2026 | 31 days in Billing Cycle", 'That Capital One PDF doesn’t have the account summary this reads');
        $this->assertRefused('An invoice from somebody', 'That PDF isn’t a statement this can read');
    }

    private function assertRefused(string $text, string $message): void
    {
        try {
            BankStatement::fromText($text);
            $this->fail('It was read.');
        } catch (LedgerException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_reconciling_from_the_pdf(): void
    {
        Storage::fake(config('filesystems.private_disk'));

        // The books: the client's payment, the rent (dated a day off) and
        // check 8670. The library deposit and check 8672 aren't in yet.
        Transaction::create(['type' => 'income', 'amount' => 500, 'category' => 'Other income', 'description' => 'Client A', 'occurred_on' => '2026-09-05', 'deposit_account_id' => $this->checking->id]);
        foreach ([['Rent', 100, '2026-09-09'], ['Check 8670', 50, '2026-09-20']] as [$name, $amount, $date]) {
            Expense::create(['name' => $name, 'amount' => $amount, 'date' => $date, 'is_billable' => false, 'billing_status' => 'unbilled', 'paid_from_account_id' => $this->checking->id]);
        }

        // Read first: its figures, and it starts where the books do.
        $this->actingAs($this->admin)->post('/api/reconciliations/preview', ['account_id' => $this->checking->id, 'statement_pdf' => $this->pdf()], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('statement_date', '2026-09-30')
            ->assertJsonPath('ending_cents', 42500)
            ->assertJsonPath('rows', 5)
            ->assertJsonPath('totals.out_count', 3)
            ->assertJsonPath('beginning_cents', 0)
            ->assertJsonPath('cleared_cents', 0);

        // Started from it -- no date or balance typed -- its rows tick the
        // three the books have.
        $id = $this->actingAs($this->admin)->post('/api/reconciliations', ['account_id' => $this->checking->id, 'statement_pdf' => $this->pdf()], ['Accept' => 'application/json'])
            ->assertOk()->json('id');
        $r = BankReconciliation::find($id);
        $this->assertSame(['2026-09-30', 42500, 'statement.pdf'], [$r->statement_date->toDateString(), $r->statement_ending_balance_cents, $r->statement_file_name]);
        Storage::disk(config('filesystems.private_disk'))->assertExists($r->statement_file_path);

        // The library deposit is where the books and the bank part ways.
        $this->actingAs($this->admin)->get("/bookkeeping/reconcile/{$id}")->assertInertia(fn ($p) => $p
            ->where('reconciliation.has_statement_file', true)
            ->where('reconciliation.beginning_off_cents', null)
            ->where('summary.difference_cents', 7500)
            ->has('missing', 2)
            ->where('missing.0.description', 'Cashflow36 GROSSE POINTE PU PUBLIC LIBRARY Cashflow360 016SYMTTU48')
            ->where('missing.1.description', 'Check 8672')
            ->where('first_day_off', ['date' => '2026-09-12', 'bank_cents' => 50000, 'books_cents' => 40000]));
        $this->actingAs($this->admin)->get("/bookkeeping/reconcile/{$id}/statement")->assertOk();

        // Both added: it agrees, every day, and finishes.
        $missing = collect($r->fresh()->statement_rows)->whereNull('line_id')->keys();
        foreach ($missing as $row) {
            $this->actingAs($this->admin)->postJson("/api/reconciliations/{$id}/rows/{$row}/add")->assertNoContent();
        }
        $this->actingAs($this->admin)->get("/bookkeeping/reconcile/{$id}")->assertInertia(fn ($p) => $p->where('summary.difference_cents', 0)->where('first_day_off', null));
        $this->actingAs($this->admin)->postJson("/api/reconciliations/{$id}/finish")->assertNoContent();
    }

    public function test_a_statement_that_doesnt_start_where_the_books_do_is_flagged(): void
    {
        Storage::fake(config('filesystems.private_disk'));
        $text = str_replace(
            ["Beginning Balance\t$0.00", "Ending Balance\t$425.00", "XXXXXXXX1234\t$425.00"],
            ["Beginning Balance\t$10.00", "Ending Balance\t$435.00", "XXXXXXXX1234\t$435.00"],
            self::STATEMENT,
        );

        $this->actingAs($this->admin)->post('/api/reconciliations/preview', ['account_id' => $this->checking->id, 'statement_pdf' => $this->pdf($text)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('beginning_cents', 1000)->assertJsonPath('cleared_cents', 0);
        $id = $this->actingAs($this->admin)->post('/api/reconciliations', ['account_id' => $this->checking->id, 'statement_pdf' => $this->pdf($text)], ['Accept' => 'application/json'])->json('id');

        $this->actingAs($this->admin)->get("/bookkeeping/reconcile/{$id}")->assertInertia(fn ($p) => $p->where('reconciliation.beginning_off_cents', 1000));

        // Dropped: its PDF goes with it.
        $path = BankReconciliation::find($id)->statement_file_path;
        $this->actingAs($this->admin)->deleteJson("/api/reconciliations/{$id}")->assertNoContent();
        Storage::disk(config('filesystems.private_disk'))->assertMissing($path);
    }

    public function test_a_card_statement_is_read_across_the_new_year(): void
    {
        $s = BankStatement::fromText(self::CARD);

        $this->assertSame(['Capital One', 'capital_one_card', '4321', '2026-12-03', '2027-01-02', 100000, 19500],
            [$s->bank, $s->accountKey, $s->accountLast4, $s->periodStart, $s->statementDate, $s->beginningCents, $s->endingCents]);
        // By when each was made: December's in 2026 -- the one before the
        // cycle began too -- January's in 2027. Paid and credited are less
        // owed; interest is dated the cycle's last day.
        $this->assertSame([
            ['date' => '2026-12-01', 'cents' => -10000, 'description' => 'Amazon web services SEATTLE WA'],
            ['date' => '2026-12-12', 'cents' => -3500, 'description' => 'MOO PRINT WILMINGTON DE'],
            ['date' => '2026-12-14', 'cents' => 500, 'description' => 'MOO PRINT WILMINGTON DE'],
            ['date' => '2026-12-20', 'cents' => 100000, 'description' => 'CAPITAL ONE ONLINE PYMT'],
            ['date' => '2027-01-01', 'cents' => -5000, 'description' => 'Google Workspace_madma Mountain View CA'],
            ['date' => '2027-01-02', 'cents' => -1500, 'description' => 'Interest charged'],
        ], $s->rows);
        $this->assertSame(['in_count' => 2, 'in_cents' => 100500, 'out_count' => 4, 'out_cents' => 20000], $s->totals);

        // A charge lost in the reading doesn't come to its cardholder's total.
        $this->assertRefused(str_replace("Dec 12 Dec 13 MOO PRINT WILMINGTON DE\t$35.00\n", '', self::CARD),
            'Read $0.00 of charges on the card ending 8765, but the statement says $35.00.');
    }

    public function test_reconciling_the_card_from_its_pdf(): void
    {
        Storage::fake(config('filesystems.private_disk'));
        $card = Account::forKey('capital_one_card');
        $this->travelTo('2027-01-10');

        // The books: last cycle's $1,000.00 (reconciled), then this one's
        // Amazon and Google charges and the payment from checking. The Moo
        // charge and credit, and the interest, aren't in yet.
        Expense::create(['name' => 'Earlier', 'amount' => 1000, 'date' => '2026-11-20', 'is_billable' => false, 'billing_status' => 'unbilled', 'paid_from_account_id' => $card->id]);
        app(\App\Services\Reconciliation\Reconciler::class)->reconcileThrough($card, '2026-12-02', 100000);
        foreach ([['Amazon web services', 100, '2026-12-01'], ['Google Workspace', 50, '2027-01-01']] as [$name, $amount, $date]) {
            Expense::create(['name' => $name, 'amount' => $amount, 'date' => $date, 'is_billable' => false, 'billing_status' => 'unbilled', 'paid_from_account_id' => $card->id]);
        }
        $this->actingAs($this->admin)->postJson('/api/journal-entries', [
            'entry_date' => '2026-12-20', 'memo' => 'Card payment',
            'lines' => [
                ['account_id' => $card->id, 'debit' => 1000],
                ['account_id' => $this->checking->id, 'credit' => 1000],
            ],
        ])->assertOk();

        $this->actingAs($this->admin)->post('/api/reconciliations/preview', ['account_id' => $card->id, 'statement_pdf' => $this->pdf(self::CARD)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('bank', 'Capital One')->assertJsonPath('beginning_cents', 100000)->assertJsonPath('cleared_cents', 100000)
            ->assertJsonPath('last_date', '2026-12-02')->assertJsonPath('period_start', '2026-12-03');

        // The charge made Dec 1, before the cycle, is still this statement's.
        $id = $this->actingAs($this->admin)->post('/api/reconciliations', ['account_id' => $card->id, 'statement_pdf' => $this->pdf(self::CARD)], ['Accept' => 'application/json'])
            ->assertOk()->json('id');
        $this->actingAs($this->admin)->get("/bookkeeping/reconcile/{$id}")->assertInertia(fn ($p) => $p
            ->where('reconciliation.beginning_off_cents', null)
            ->where('first_day_off', null)
            ->has('missing', 3)
            ->where('missing', fn ($rows) => collect($rows)->pluck('description')->sort()->values()->all() === ['Interest charged', 'MOO PRINT WILMINGTON DE', 'MOO PRINT WILMINGTON DE'])
            ->where('lines', fn ($lines) => collect($lines)->where('cleared', true)->count() === 3));

        foreach (collect(BankReconciliation::find($id)->statement_rows)->whereNull('line_id')->keys() as $row) {
            $this->actingAs($this->admin)->postJson("/api/reconciliations/{$id}/rows/{$row}/add")->assertNoContent();
        }
        $this->actingAs($this->admin)->postJson("/api/reconciliations/{$id}/finish")->assertNoContent();
    }

    public function test_another_accounts_statement_is_refused(): void
    {
        $card = Account::forKey('capital_one_card');

        $this->actingAs($this->admin)->post('/api/reconciliations/preview', ['account_id' => $card->id, 'statement_pdf' => $this->pdf()], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('message', 'That’s a Waterford statement (account ending 1234), not '.$card->name.'’s.');
        $this->actingAs($this->admin)->post('/api/reconciliations', ['account_id' => $card->id, 'statement_pdf' => $this->pdf()], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $this->assertSame(0, BankReconciliation::count());
    }
}
