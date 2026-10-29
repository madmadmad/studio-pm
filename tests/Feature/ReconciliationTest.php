<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\Expense;
use App\Models\JournalLine;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\ReconciliationDue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

// Monthly bank and card reconciliation (CPA review, Oct 2026).
class ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Account $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->card = Account::forKey('capital_one_card');
        foreach ([['Adobe', 100, '2026-09-03'], ['Netlify', 50, '2026-09-10'], ['Figma', 20, '2026-09-28']] as [$name, $amount, $date]) {
            $this->actingAs($this->admin)->postJson('/api/expenses', ['name' => $name, 'amount' => $amount, 'date' => $date])->assertCreated();
        }
    }

    // September's card statement: two of the charges, and a credit the
    // books don't have. $145 owed at the end.
    private function startSeptember(?string $csv = null)
    {
        $csv ??= implode("\n", [
            'Transaction Date,Posted Date,Card No.,Description,Category,Debit,Credit',
            '2026-09-03,2026-09-04,7790,ADOBE,Software,100.00,',
            '2026-09-10,2026-09-11,7790,NETLIFY,Internet,50.00,',
            '2026-09-12,2026-09-13,7790,MOO PRINT,Other Services,,5.00',
        ]);

        return $this->actingAs($this->admin)->post('/api/reconciliations', [
            'account_id' => $this->card->id, 'statement_date' => '2026-09-30', 'ending_balance' => '145.00',
            'statement' => UploadedFile::fake()->createWithContent('card.csv', $csv),
        ], ['Accept' => 'application/json']);
    }

    private function page(int $id)
    {
        return $this->actingAs($this->admin)->get("/bookkeeping/reconcile/{$id}");
    }

    public function test_a_statement_is_matched_from_its_export_completed_and_then_fixed(): void
    {
        $id = $this->startSeptember()->assertOk()->json('id');

        // The export ticked its two charges; the credit is missing; Figma
        // (not on this statement) stays open.
        $this->page($id)->assertInertia(fn ($p) => $p
            ->where('summary.cleared_balance_cents', 15000)
            ->where('summary.difference_cents', -500)
            ->has('missing', 1)
            ->where('missing.0.description', 'MOO PRINT')
            ->where('lines', fn ($lines) => collect($lines)->where('cleared', true)->count() === 2 && collect($lines)->count() === 3));

        $this->actingAs($this->admin)->postJson("/api/reconciliations/{$id}/finish")
            ->assertUnprocessable()->assertJsonPath('message', 'It’s off by $5.00. What’s cleared has to come to the statement’s ending balance.');

        // Added from the statement: money back, ticked.
        $this->actingAs($this->admin)->postJson("/api/reconciliations/{$id}/rows/2/add")->assertNoContent();
        $this->assertSame('-5.00', Expense::where('name', 'MOO PRINT')->sole()->amount);
        $this->actingAs($this->admin)->postJson("/api/reconciliations/{$id}/finish")->assertNoContent();
        $this->assertNotNull(BankReconciliation::find($id)->completed_at);

        // Its entries are fixed now; one not on it isn't.
        $adobe = Expense::firstWhere('name', 'Adobe');
        $this->actingAs($this->admin)->putJson("/api/expenses/{$adobe->id}", ['amount' => 110])
            ->assertUnprocessable()->assertJsonPath('message', 'This is on the reconciled Capital One Card, Sep 30, 2026 statement, so it can\'t be changed. Reopen that reconciliation first.');
        $figma = Expense::firstWhere('name', 'Figma');
        $this->actingAs($this->admin)->putJson("/api/expenses/{$figma->id}", ['amount' => 25])->assertOk();

        // Reopened, it can be fixed.
        $this->actingAs($this->admin)->postJson("/api/reconciliations/{$id}/reopen")->assertNoContent();
        $this->actingAs($this->admin)->putJson("/api/expenses/{$adobe->id}", ['amount' => 110])->assertOk();
    }

    public function test_lines_are_ticked_by_hand_and_a_statement_in_progress_can_be_dropped(): void
    {
        $id = $this->actingAs($this->admin)->postJson('/api/reconciliations', [
            'account_id' => $this->card->id, 'statement_date' => '2026-09-30', 'ending_balance' => '150.00',
        ])->assertOk()->json('id');
        $lines = JournalLine::where('account_id', $this->card->id)->with('entry')->get()->keyBy(fn ($l) => $l->entry->memo);

        $this->actingAs($this->admin)->postJson("/api/reconciliations/{$id}/lines/{$lines['Adobe']->id}", ['cleared' => true])->assertJsonPath('difference_cents', 5000);
        $this->actingAs($this->admin)->postJson("/api/reconciliations/{$id}/lines/{$lines['Netlify']->id}", ['cleared' => true])->assertJsonPath('difference_cents', 0);

        $this->actingAs($this->admin)->deleteJson("/api/reconciliations/{$id}")->assertNoContent();
        $this->assertSame(0, JournalLine::whereNotNull('bank_reconciliation_id')->count());
    }

    public function test_the_next_statement_starts_where_the_last_ended(): void
    {
        $id = $this->startSeptember()->json('id');
        $this->actingAs($this->admin)->postJson("/api/reconciliations/{$id}/rows/2/add");
        $this->actingAs($this->admin)->postJson("/api/reconciliations/{$id}/finish")->assertNoContent();

        $this->actingAs($this->admin)->postJson('/api/reconciliations', ['account_id' => $this->card->id, 'statement_date' => '2026-09-15', 'ending_balance' => '0'])
            ->assertUnprocessable()->assertJsonPath('message', 'Capital One Card is reconciled through Sep 30, 2026. Pick a later statement date.');
        $next = $this->actingAs($this->admin)->postJson('/api/reconciliations', ['account_id' => $this->card->id, 'statement_date' => '2026-10-15', 'ending_balance' => '165.00'])->json('id');

        // Figma is still waiting; $145 carried over.
        $this->page($next)->assertInertia(fn ($p) => $p->where('summary.starting_cents', 14500)->has('lines', 1));
    }

    public function test_history_is_reconciled_in_one_step_at_cutover(): void
    {
        $this->artisan('reconcile:through', ['account' => 'capital_one_card', 'date' => '2026-09-30', 'balance' => '100'])
            ->expectsOutputToContain('The books come to $170.00')->assertFailed();
        $this->assertSame(0, BankReconciliation::count());

        $this->artisan('reconcile:through', ['account' => 'capital_one_card', 'date' => '2026-09-30', 'balance' => '170'])
            ->expectsOutputToContain('3 lines')->assertSuccessful();
    }

    public function test_a_deposit_missing_from_checking_is_added_as_income(): void
    {
        $checking = Account::forKey('checking');
        $id = $this->actingAs($this->admin)->post('/api/reconciliations', [
            'account_id' => $checking->id, 'statement_date' => '2026-09-30', 'ending_balance' => '250.00',
            'statement' => UploadedFile::fake()->createWithContent('bank.csv', "Date,Description,Comments,Check Number,Amount,Balance\n09/20/2026,Mobile deposit,,,$250.00,$250.00\n09/29/2026,Card fee (Pending),,,-$5.00,\n"),
        ], ['Accept' => 'application/json'])->assertOk()->json('id');

        $this->page($id)->assertInertia(fn ($p) => $p->has('missing', 1, fn ($row) => $row->where('cents', 25000)->etc()));
        $this->actingAs($this->admin)->postJson("/api/reconciliations/{$id}/rows/0/add")->assertNoContent();

        $this->assertSame(25000, $checking->netDebitCents());
        $this->assertSame('Mobile deposit', Transaction::sole()->description);
        $this->actingAs($this->admin)->postJson("/api/reconciliations/{$id}/finish")->assertNoContent();
    }

    public function test_the_accountant_sees_reconciliations_but_changes_nothing(): void
    {
        $id = $this->startSeptember()->json('id');
        $accountant = User::factory()->create(['role' => User::ROLE_ACCOUNTANT]);

        $this->actingAs($accountant)->get('/bookkeeping/reconcile')->assertOk();
        $this->actingAs($accountant)->get("/bookkeeping/reconcile/{$id}")->assertOk();
        $this->actingAs($accountant)->get("/bookkeeping/reconcile/{$id}.csv")->assertOk();
        $this->actingAs($accountant)->postJson("/api/reconciliations/{$id}/finish")->assertForbidden();
    }

    public function test_the_bookkeepers_are_reminded_of_accounts_not_reconciled(): void
    {
        Notification::fake();

        $this->artisan('reconcile:remind')->assertSuccessful();

        Notification::assertSentTo($this->admin, ReconciliationDue::class, fn (ReconciliationDue $n) => str_contains($n->toArray($this->admin)['message'], 'Capital One Card (never)'));
    }
}
