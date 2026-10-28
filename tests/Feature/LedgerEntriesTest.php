<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

// Bookkeeping > Journal: entries made by hand (general, transfer, payout,
// payroll), reversing them, locking periods, and editing the chart.
class LedgerEntriesTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create();
    }

    private function balance(string $keyOrName): int
    {
        return Account::where('system_key', $keyOrName)->orWhere('name', $keyOrName)->firstOrFail()->netDebitCents();
    }

    private function bookkeeper(): User
    {
        $user = User::factory()->teamMember()->create();
        $user->forceFill(['permissions' => ['bookkeeping']])->save();

        return $user;
    }

    public function test_a_general_entry_posts_its_lines(): void
    {
        $this->actingAs($this->manager)->postJson('/api/journal-entries', [
            'entry_date' => '2025-01-01',
            'memo' => 'Opening balances',
            'lines' => [
                ['account_id' => Account::forKey('checking')->id, 'debit' => '40000.00'],
                ['account_id' => Account::forKey('capital_one_card')->id, 'credit' => '3000'],
                ['account_id' => Account::forKey('opening_balance_equity')->id, 'credit' => 37000, 'description' => 'The difference'],
            ],
        ])->assertOk()
            ->assertJsonPath('memo', 'Opening balances')
            ->assertJsonPath('total_cents', 4000000)
            ->assertJsonPath('manual', true)
            ->assertJsonPath('lines.2.description', 'The difference');

        $this->assertSame(4000000, $this->balance('checking'));
        $this->assertSame(-300000, $this->balance('capital_one_card'));
    }

    public function test_an_unbalanced_entry_is_refused_with_the_difference(): void
    {
        $this->actingAs($this->manager)->postJson('/api/journal-entries', [
            'entry_date' => '2026-03-01',
            'memo' => 'Oops',
            'lines' => [
                ['account_id' => Account::forKey('checking')->id, 'debit' => '100'],
                ['account_id' => Account::forKey('other_income')->id, 'credit' => '90'],
            ],
        ])->assertUnprocessable()->assertJsonPath('message', 'Debits ($100.00) and credits ($90.00) don\'t balance.');

        $this->assertSame(0, JournalEntry::count());
    }

    public function test_paying_the_card_from_checking_is_a_transfer(): void
    {
        $this->actingAs($this->manager)->postJson('/api/journal-entries/transfer', [
            'entry_date' => '2026-03-25',
            'from_account_id' => Account::forKey('checking')->id,
            'to_account_id' => Account::forKey('capital_one_card')->id,
            'amount' => '2412.07',
        ])->assertOk()->assertJsonPath('memo', 'Transfer from Checking – Waterford Bank to Capital One Card');

        $this->assertSame(241207, $this->balance('capital_one_card'));
        $this->assertSame(-241207, $this->balance('checking'));
        $this->assertSame(0, Account::where('type', Account::EXPENSE)->get()->sum->netDebitCents(), 'never an expense');
    }

    public function test_a_transfer_is_between_two_different_balance_sheet_accounts(): void
    {
        $checking = Account::forKey('checking')->id;

        $this->actingAs($this->manager)->postJson('/api/journal-entries/transfer', ['entry_date' => '2026-03-25', 'from_account_id' => $checking, 'to_account_id' => $checking, 'amount' => 10])
            ->assertUnprocessable()->assertJsonValidationErrors('to_account_id');
        $this->actingAs($this->manager)->postJson('/api/journal-entries/transfer', ['entry_date' => '2026-03-25', 'from_account_id' => $checking, 'to_account_id' => Account::forKey('wages')->id, 'amount' => 10])
            ->assertUnprocessable()->assertJsonValidationErrors('to_account_id');
    }

    public function test_a_stripe_payout_moves_clearing_into_checking_with_any_fees_not_yet_booked(): void
    {
        $this->actingAs($this->manager)->postJson('/api/journal-entries/payout', ['entry_date' => '2026-03-27', 'deposited' => '970.00', 'fees' => '30.00'])
            ->assertOk()->assertJsonPath('memo', 'Stripe payout');

        $this->assertSame(97000, $this->balance('checking'));
        $this->assertSame(3000, $this->balance('merchant_fees'));
        $this->assertSame(-100000, $this->balance('stripe_clearing'));
    }

    public function test_payroll_splits_into_its_accounts_and_the_two_debits_come_out_of_checking(): void
    {
        $entry = $this->actingAs($this->manager)->postJson('/api/journal-entries/payroll', [
            'entry_date' => '2026-03-15',
            'memo' => 'Payroll, Mar 1-15',
            'wages' => '6500',
            'payroll_taxes' => '620.50',
            'retirement_expense' => '195',
            'payroll_debit' => '6400.50',
            'retirement_debit' => '815',
            'health_deduction' => '100',
        ])->assertOk();

        $this->assertCount(6, $entry->json('lines'));
        $this->assertSame('Total Cash Requirement (Data Service)', $entry->json('lines.3.description'));
        $this->assertSame(650000, $this->balance('wages'));
        $this->assertSame(0, $this->balance('officer_compensation'));
        $this->assertSame(62050, $this->balance('payroll_taxes'));
        $this->assertSame(19500, $this->balance('retirement_expense'));
        // Two lines out of checking, as the bank shows them...
        $this->assertSame(-721550, $this->balance('checking'));
        // ...and the employees' health deduction back against the premiums.
        $this->assertSame(-10000, $this->balance('health_insurance'));
    }

    public function test_payroll_with_no_health_deduction_or_retirement_debit(): void
    {
        $this->actingAs($this->manager)->postJson('/api/journal-entries/payroll', [
            'entry_date' => '2026-03-15',
            'wages' => '2500',
            'payroll_taxes' => '191.25',
            'payroll_debit' => '2691.25',
        ])->assertOk()->assertJsonCount(3, 'lines');

        $this->assertSame(-269125, $this->balance('checking'));
        $this->assertSame(0, $this->balance('health_insurance'));
    }

    public function test_payroll_that_does_not_balance_is_refused(): void
    {
        $this->actingAs($this->manager)->postJson('/api/journal-entries/payroll', [
            'entry_date' => '2026-03-15',
            'wages' => '2500',
            'payroll_taxes' => '191.25',
            'payroll_debit' => '2600',
        ])->assertStatus(422)->assertJsonPath('message', 'The costs come to $2,691.25 but what left checking plus the health deduction comes to $2,600.00. Check the amounts against the payroll report.');

        $this->assertSame(0, JournalEntry::count());
    }

    public function test_payroll_needs_an_amount_and_the_data_service_debit(): void
    {
        $this->actingAs($this->manager)->postJson('/api/journal-entries/payroll', ['entry_date' => '2026-03-15', 'wages' => '0', 'payroll_debit' => '10'])
            ->assertUnprocessable()->assertJsonPath('message', 'Enter at least one payroll amount.');

        $this->actingAs($this->manager)->postJson('/api/journal-entries/payroll', ['entry_date' => '2026-03-15', 'wages' => '2500'])
            ->assertUnprocessable()->assertJsonValidationErrors('payroll_debit');
    }

    public function test_payroll_refuses_more_out_of_checking_than_it_costs(): void
    {
        $this->actingAs($this->manager)->postJson('/api/journal-entries/payroll', [
            'entry_date' => '2026-03-15', 'wages' => '2500', 'payroll_debit' => '2400', 'retirement_debit' => '200',
        ])->assertUnprocessable()->assertJsonPath('message', 'The costs come to $2,500.00 but what left checking plus the health deduction comes to $2,600.00. Check the amounts against the payroll report.');

        $this->assertSame(0, $this->balance('checking'));
    }

    public function test_an_entry_made_by_hand_is_reversed_and_one_the_app_posted_isnt(): void
    {
        $manual = $this->actingAs($this->manager)->postJson('/api/journal-entries/payout', ['entry_date' => '2026-03-27', 'deposited' => '50'])->json('id');
        $expense = Expense::create(['name' => 'Spectrum', 'amount' => '120.00', 'date' => '2026-03-04', 'is_billable' => false]);
        $posted = JournalEntry::where('source_type', $expense->getMorphClass())->value('id');

        $this->actingAs($this->manager)->postJson("/api/journal-entries/{$manual}/reverse")->assertOk()->assertJsonPath('reversed_by.entry_number', 3);
        $this->assertSame(0, $this->balance('checking'));

        $this->actingAs($this->manager)->postJson("/api/journal-entries/{$posted}/reverse")
            ->assertUnprocessable()->assertJsonPath('message', 'This entry follows its expense or payment. Change or delete that instead.');
    }

    public function test_an_entry_in_a_locked_period_is_reversed_into_an_open_date(): void
    {
        $id = $this->actingAs($this->manager)->postJson('/api/journal-entries/payout', ['entry_date' => '2026-03-27', 'deposited' => '50'])->json('id');
        $this->actingAs($this->manager)->postJson('/api/accounting-periods', ['starts_on' => '2026-03-01', 'ends_on' => '2026-03-31'])->assertCreated();

        $this->actingAs($this->manager)->postJson("/api/journal-entries/{$id}/reverse")->assertUnprocessable();
        $this->actingAs($this->manager)->postJson("/api/journal-entries/{$id}/reverse", ['entry_date' => '2026-04-01'])->assertOk();
    }

    public function test_only_a_super_admin_locks_and_unlocks_periods(): void
    {
        $bookkeeper = $this->bookkeeper();

        $this->actingAs($bookkeeper)->postJson('/api/accounting-periods', ['starts_on' => '2026-01-01', 'ends_on' => '2026-03-31'])->assertForbidden();

        $period = $this->actingAs($this->manager)->postJson('/api/accounting-periods', ['starts_on' => '2026-01-01', 'ends_on' => '2026-03-31'])
            ->assertCreated()->assertJsonPath('locker.name', $this->manager->name)->json('id');
        $this->actingAs($bookkeeper)->postJson('/api/journal-entries/payout', ['entry_date' => '2026-02-01', 'deposited' => '50'])->assertUnprocessable();

        $this->actingAs($bookkeeper)->postJson("/api/accounting-periods/{$period}/unlock")->assertForbidden();
        $this->actingAs($this->manager)->postJson("/api/accounting-periods/{$period}/unlock")->assertOk();
        $this->assertNull(AccountingPeriod::find($period)->locked_at);
        $this->actingAs($bookkeeper)->postJson('/api/journal-entries/payout', ['entry_date' => '2026-02-01', 'deposited' => '50'])->assertOk();
    }

    public function test_an_account_is_added_under_a_heading_and_takes_its_type(): void
    {
        $this->actingAs($this->bookkeeper())->postJson('/api/accounts', [
            'code' => '1020',
            'name' => 'Savings – Waterford Bank',
            'parent_id' => Account::forKey('assets')->id,
        ])->assertCreated()->assertJsonPath('type', Account::ASSET)->assertJsonPath('is_active', true);

        $this->actingAs($this->manager)->postJson('/api/accounts', ['code' => '1020', 'name' => 'Again', 'parent_id' => Account::forKey('assets')->id])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->actingAs($this->manager)->postJson('/api/accounts', ['code' => '1030', 'name' => 'Under an account', 'parent_id' => Account::forKey('checking')->id])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    }

    public function test_an_account_is_renamed_renumbered_and_deactivated_but_the_apps_own_stay_active(): void
    {
        $internet = Account::where('name', 'Internet')->first();

        $this->actingAs($this->manager)->patchJson("/api/accounts/{$internet->id}", ['code' => '6181', 'name' => 'Internet & Wi-Fi'])
            ->assertOk()->assertJsonPath('code_is_placeholder', false);
        $this->actingAs($this->manager)->patchJson("/api/accounts/{$internet->id}", ['is_active' => false])->assertOk();
        $this->assertFalse($internet->fresh()->is_active);

        $this->actingAs($this->manager)->patchJson('/api/accounts/'.Account::forKey('checking')->id, ['is_active' => false])
            ->assertUnprocessable()->assertJsonPath('message', 'The app posts to Checking – Waterford Bank on its own, so it can\'t be deactivated.');
        $this->actingAs($this->manager)->patchJson("/api/accounts/{$internet->id}", ['parent_id' => Account::forKey('income')->id])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    }

    public function test_the_journal_lists_the_years_entries_with_where_they_came_from(): void
    {
        Expense::create(['name' => 'Spectrum', 'amount' => '120.00', 'date' => '2026-03-04', 'is_billable' => false]);
        $this->actingAs($this->manager)->postJson('/api/journal-entries/payout', ['entry_date' => '2026-03-27', 'deposited' => '50']);
        $this->actingAs($this->manager)->postJson('/api/journal-entries/payout', ['entry_date' => '2025-12-30', 'deposited' => '20']);

        $this->actingAs($this->bookkeeper())->get('/bookkeeping/journal?year=2026')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Bookkeeping/Journal')
            ->has('entries', 2)
            ->where('entries.0.memo', 'Stripe payout')
            ->where('entries.1.source.kind', 'Expense')
            ->where('entries.1.source.label', 'Spectrum')
            ->where('clearingCents', -7000)
            ->where('canLock', false)
            ->where('years', [2026, 2025]));
    }

    public function test_the_journal_needs_bookkeeping(): void
    {
        $member = User::factory()->teamMember()->create();

        $this->actingAs($member)->get('/bookkeeping/journal')->assertForbidden();
        $this->actingAs($member)->postJson('/api/journal-entries/payout', ['entry_date' => '2026-03-27', 'deposited' => '50'])->assertForbidden();
    }
}
