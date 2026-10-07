<?php

namespace Tests\Feature;

use App\Exceptions\LedgerException;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use RefreshDatabase;

    private Ledger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = app(Ledger::class);

        // The chart is seeded by migration. Internet has no handle of its
        // own (only accounts the code depends on do), so give it one here
        // to keep the lines short.
        Account::where('name', 'Internet')->firstOrFail()->update(['system_key' => 'internet']);
    }

    // A $120 internet bill on the card.
    private function cardCharge(int $cents = 12000): array
    {
        return [
            ['account' => 'internet', 'debit_cents' => $cents],
            ['account' => 'capital_one_card', 'credit_cents' => $cents],
        ];
    }

    public function test_a_balanced_entry_posts_with_its_lines_and_numbers_run_in_order(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $first = $this->ledger->post('2026-03-04', $this->cardCharge(), 'Spectrum, March');
        $second = $this->ledger->post('2026-03-05', $this->cardCharge(5000));

        $this->assertSame(1, $first->entry_number);
        $this->assertSame(2, $second->entry_number);
        $this->assertSame('2026-03-04', $first->entry_date->toDateString());
        $this->assertSame('Spectrum, March', $first->memo);
        $this->assertSame($user->id, $first->created_by);
        $this->assertNotNull($first->posted_at);
        $this->assertSame(12000, $first->totalCents());
        $this->assertSame(17000, (int) Account::forKey('internet')->lines()->sum('debit_cents'));
        $this->assertSame(17000, (int) Account::forKey('capital_one_card')->lines()->sum('credit_cents'));
    }

    public function test_lines_take_an_account_model_id_or_system_key_and_carry_a_client(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);

        $entry = $this->ledger->post('2026-03-04', [
            ['account' => Account::forKey('internet'), 'debit_cents' => 100, 'company_id' => $company->id, 'description' => 'Their share'],
            ['account' => Account::forKey('capital_one_card')->id, 'credit_cents' => 100],
        ]);

        $this->assertSame($company->id, $entry->lines[0]->company_id);
        $this->assertSame('Their share', $entry->lines[0]->description);
    }

    public function test_an_entry_remembers_the_record_that_caused_it(): void
    {
        $source = Company::create(['name' => 'Spectrum']); // any record can be a source; a client doesn't post itself

        $entry = $this->ledger->post('2026-03-04', $this->cardCharge(), source: $source);

        $this->assertTrue($entry->source->is($source));
        $this->assertTrue($this->ledger->liveEntryFor($source)->is($entry));
    }

    public function test_an_unbalanced_entry_is_refused_and_nothing_is_written(): void
    {
        try {
            $this->ledger->post('2026-03-04', [
                ['account' => 'internet', 'debit_cents' => 12000],
                ['account' => 'capital_one_card', 'credit_cents' => 11999],
            ]);
            $this->fail('An unbalanced entry posted.');
        } catch (LedgerException $e) {
            $this->assertSame('Debits ($120.00) and credits ($119.99) don\'t balance.', $e->getMessage());
        }

        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(0, JournalLine::count());
    }

    public static function badLines(): array
    {
        return [
            'both sides' => [['account' => 'internet', 'debit_cents' => 100, 'credit_cents' => 100]],
            'neither side' => [['account' => 'internet']],
            'zero' => [['account' => 'internet', 'debit_cents' => 0]],
            'negative' => [['account' => 'internet', 'debit_cents' => -100]],
            'dollars, not cents' => [['account' => 'internet', 'debit_cents' => 1.5]],
            'a string' => [['account' => 'internet', 'debit_cents' => '100']],
        ];
    }

    #[DataProvider('badLines')]
    public function test_each_line_must_be_exactly_one_positive_debit_or_credit_in_cents(array $line): void
    {
        $this->expectException(LedgerException::class);

        $this->ledger->post('2026-03-04', [$line, ['account' => 'capital_one_card', 'credit_cents' => 100]]);
    }

    public function test_an_entry_needs_two_lines(): void
    {
        $this->expectExceptionMessage('An entry needs at least two lines.');

        $this->ledger->post('2026-03-04', [['account' => 'internet', 'debit_cents' => 100]]);
    }

    public function test_an_unknown_or_inactive_account_is_refused(): void
    {
        try {
            $this->ledger->post('2026-03-04', [
                ['account' => 'no_such_account', 'debit_cents' => 100],
                ['account' => 'capital_one_card', 'credit_cents' => 100],
            ]);
            $this->fail('Posted to an account that doesn\'t exist.');
        } catch (LedgerException $e) {
            $this->assertStringContainsString('"no_such_account"', $e->getMessage());
        }

        Account::forKey('internet')->update(['is_active' => false]);

        $this->expectExceptionMessage('"Internet" is inactive.');
        $this->ledger->post('2026-03-04', $this->cardCharge());
    }

    public function test_posted_entries_and_lines_cant_be_changed_or_deleted(): void
    {
        $entry = $this->ledger->post('2026-03-04', $this->cardCharge());
        $line = $entry->lines->first();

        $attempts = [
            fn () => $entry->update(['memo' => 'Edited']),
            fn () => $entry->delete(),
            fn () => $line->update(['debit_cents' => 1]),
            fn () => $line->delete(),
        ];
        foreach ($attempts as $attempt) {
            try {
                $attempt();
                $this->fail('A posted entry or line changed.');
            } catch (LedgerException) {
                // Refused, as it should be.
            }
        }

        $this->assertNull($entry->fresh()->memo);
        $this->assertSame(12000, $line->fresh()->debit_cents);
        $this->assertSame(2, JournalLine::count());
    }

    public function test_a_line_can_still_be_marked_reconciled(): void
    {
        $line = $this->ledger->post('2026-03-04', $this->cardCharge())->lines->last();
        $statement = BankReconciliation::create([
            'account_id' => Account::forKey('capital_one_card')->id,
            'statement_date' => '2026-03-31',
            'statement_ending_balance_cents' => -12000,
        ]);

        $line->update(['bank_reconciliation_id' => $statement->id]);

        $this->assertSame($statement->id, $line->fresh()->bank_reconciliation_id);
    }

    public function test_a_group_heading_cant_be_posted_to(): void
    {
        $this->expectExceptionMessage('"Operating Expenses" is a group heading.');

        $this->ledger->post('2026-03-04', [
            ['account' => 'operating_expenses', 'debit_cents' => 100],
            ['account' => 'capital_one_card', 'credit_cents' => 100],
        ]);
    }

    public function test_accounts_are_deactivated_never_deleted(): void
    {
        $this->expectExceptionMessage('Accounts are never deleted. Deactivate "Internet" instead.');

        Account::forKey('internet')->delete();
    }

    public function test_a_client_with_ledger_history_cant_be_deleted(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $this->ledger->post('2026-03-04', [
            ['account' => 'internet', 'debit_cents' => 100, 'company_id' => $company->id],
            ['account' => 'capital_one_card', 'credit_cents' => 100],
        ]);

        $this->expectException(QueryException::class);
        $company->delete();
    }

    public function test_a_reversal_mirrors_the_entry_and_links_back_to_it(): void
    {
        $source = Company::create(['name' => 'Spectrum']); // any record can be a source; a client doesn't post itself
        $entry = $this->ledger->post('2026-03-04', $this->cardCharge(), source: $source);

        $reversal = $this->ledger->reverse($entry);

        $this->assertSame($entry->id, $reversal->reverses_entry_id);
        $this->assertTrue($entry->reversal->is($reversal));
        $this->assertSame('2026-03-04', $reversal->entry_date->toDateString(), 'dated like the original');
        $this->assertSame('Reverses entry #1', $reversal->memo);
        $this->assertTrue($reversal->source->is($source));
        $this->assertNull($this->ledger->liveEntryFor($source), 'nothing stands for the source any more');

        // Debits became credits, and every account nets to zero.
        $this->assertSame(12000, $reversal->lines->firstWhere('account_id', Account::forKey('capital_one_card')->id)->debit_cents);
        foreach (['internet', 'capital_one_card'] as $key) {
            $lines = Account::forKey($key)->lines();
            $this->assertSame(0, (int) $lines->sum('debit_cents') - (int) $lines->sum('credit_cents'));
        }
    }

    public function test_an_edit_is_a_reversal_then_a_new_entry(): void
    {
        $source = Company::create(['name' => 'Spectrum']); // any record can be a source; a client doesn't post itself
        $original = $this->ledger->post('2026-03-04', $this->cardCharge(), source: $source);

        $this->ledger->reverse($original);
        $corrected = $this->ledger->post('2026-03-04', $this->cardCharge(13000), source: $source);

        $this->assertTrue($this->ledger->liveEntryFor($source)->is($corrected));
        $this->assertSame(13000, (int) Account::forKey('internet')->lines()->sum('debit_cents') - (int) Account::forKey('internet')->lines()->sum('credit_cents'));
    }

    public function test_an_entry_is_reversed_at_most_once_and_a_reversal_isnt_reversed(): void
    {
        $entry = $this->ledger->post('2026-03-04', $this->cardCharge());
        $reversal = $this->ledger->reverse($entry);

        try {
            $this->ledger->reverse($entry->fresh());
            $this->fail('Reversed the same entry twice.');
        } catch (LedgerException $e) {
            $this->assertSame('Entry #1 has already been reversed.', $e->getMessage());
        }

        $this->expectExceptionMessage('Entry #2 is itself a reversal.');
        $this->ledger->reverse($reversal);
    }

    public function test_nothing_can_be_dated_inside_a_locked_period(): void
    {
        $period = $this->ledger->lockPeriod('2026-01-01', '2026-03-31');

        foreach (['2026-01-01', '2026-02-15', '2026-03-31'] as $date) {
            try {
                $this->ledger->post($date, $this->cardCharge());
                $this->fail("Posted into a locked period on {$date}.");
            } catch (LedgerException $e) {
                $this->assertStringStartsWith('The books are locked from Jan 1, 2026 to Mar 31, 2026', $e->getMessage());
            }
        }

        // Either side of it is open.
        $this->ledger->post('2025-12-31', $this->cardCharge());
        $this->ledger->post('2026-04-01', $this->cardCharge());

        // And unlocking reopens it.
        $this->ledger->unlockPeriod($period);
        $this->ledger->post('2026-02-15', $this->cardCharge());

        $this->assertSame(3, JournalEntry::count());
    }

    public function test_an_entry_in_a_locked_period_can_only_be_reversed_into_an_open_one(): void
    {
        $entry = $this->ledger->post('2026-03-04', $this->cardCharge());
        $this->ledger->lockPeriod('2026-03-01', '2026-03-31');

        try {
            $this->ledger->reverse($entry);
            $this->fail('Reversed into a locked period.');
        } catch (LedgerException) {
            // Its own date is locked.
        }

        $reversal = $this->ledger->reverse($entry, '2026-04-01');
        $this->assertSame('2026-04-01', $reversal->entry_date->toDateString());
    }

    public function test_a_period_cant_end_before_it_starts(): void
    {
        $this->expectException(LedgerException::class);

        $this->ledger->lockPeriod('2026-03-31', '2026-03-01');
    }

    public static function linesTheDatabaseRejects(): array
    {
        return [
            'both sides' => [100, 100],
            'neither side' => [0, 0],
            'negative debit' => [-100, 0],
            'negative credit' => [0, -100],
        ];
    }

    // The CHECK constraint itself, bypassing the Ledger and the models --
    // also proves no later migration has rebuilt the table and dropped it.
    #[DataProvider('linesTheDatabaseRejects')]
    public function test_the_database_itself_rejects_a_line_that_isnt_one_positive_side(int $debit, int $credit): void
    {
        $entry = $this->ledger->post('2026-03-04', $this->cardCharge());

        $this->expectException(QueryException::class);

        DB::table('journal_lines')->insert([
            'journal_entry_id' => $entry->id,
            'account_id' => Account::forKey('internet')->id,
            'debit_cents' => $debit,
            'credit_cents' => $credit,
        ]);
    }
}
