<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Services\BonsaiImport\StatementMatch;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatementMatchTest extends TestCase
{
    use RefreshDatabase;

    private function spend(string $date, int $cents, string $memo): void
    {
        app(Ledger::class)->post($date, [
            ['account' => 'uncategorized_expense', 'debit_cents' => $cents],
            ['account' => 'checking', 'credit_cents' => $cents],
        ], $memo);
    }

    private function row(string $date, int $cents, string $description): array
    {
        return ['date' => $date, 'cents' => $cents, 'description' => $description];
    }

    public function test_the_closest_date_wins_and_the_leftovers_are_listed(): void
    {
        $this->spend('2025-03-03', 350000, 'Rent March');
        $this->spend('2025-04-03', 350000, 'Rent April');
        $this->spend('2025-04-10', 9900, 'Something the bank never saw');

        $match = StatementMatch::run(Account::forKey('checking'), [
            $this->row('2025-04-04', -350000, 'Rent'),
            $this->row('2025-03-05', -350000, 'Rent'),
            $this->row('2025-04-15', -11139, 'AMERICAN FUNDS INVESTMENT'),
        ], '2025-01-01');

        $this->assertSame(2, $match->matched);
        $this->assertSame(['AMERICAN FUNDS INVESTMENT'], array_column($match->statementLeft, 'description'));
        $this->assertSame(['Something the bank never saw'], array_column($match->ledgerLeft, 'description'));
    }

    public function test_one_bank_payment_for_several_ledger_lines(): void
    {
        $this->spend('2025-10-29', 40000, 'Peacock Social');
        $this->spend('2025-10-29', 70000, 'Peacock Social');

        $match = StatementMatch::run(Account::forKey('checking'), [
            $this->row('2025-11-10', -110000, 'Madhouse Studio Peacock S'),
        ], '2025-01-01');

        $this->assertSame(1, $match->matched);
        $this->assertSame([], $match->statementLeft);
        $this->assertSame([], $match->ledgerLeft);
    }

    public function test_bank_and_card_exports_read_in_the_ledgers_sign(): void
    {
        $rows = StatementMatch::rows([
            ['Date' => '03/26/2025', 'Description' => 'CAPITAL ONE ONLINE PMT', 'Amount' => '-$1,000.00', 'Balance' => '$1,234.56'],
            ['Transaction Date' => '2025-03-15', 'Description' => 'NETLIFY', 'Debit' => '20.00', 'Credit' => ''],
            ['Transaction Date' => '2025-03-27', 'Description' => 'CAPITAL ONE ONLINE PYMT', 'Debit' => '', 'Credit' => '1000.00'],
        ]);

        $this->assertSame([['2025-03-26', -100000, 123456], ['2025-03-15', -2000, null], ['2025-03-27', 100000, null]],
            array_map(fn ($r) => [$r['date'], $r['cents'], $r['balance']], $rows));
    }
}
