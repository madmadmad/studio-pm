<?php

namespace App\Services\BonsaiImport;

use App\Models\Account;
use App\Models\JournalLine;
use Illuminate\Support\Carbon;

// Pairs a bank or card statement with the ledger lines of its account, so
// what's left over on either side is what the import got wrong: a charge
// on the wrong account, a deposit Bonsai never heard of, a duplicate.
//
// Amounts are the change to the account as the ledger sees it (debit
// positive): money into checking or a payment on the card is positive, a
// check or a card charge negative. First one to one -- same amount, the
// closest dates first, up to MAX_DAYS apart -- then one statement row for
// several ledger lines (or the other way round) within GROUP_DAYS: a Bonsai
// payout of a few client payments, one payroll debit for a run Bonsai
// split up.
class StatementMatch
{
    public const MAX_DAYS = 30;

    public const GROUP_DAYS = 16;

    /** @var list<array{date: string, cents: int, description: string}> */
    public array $statementLeft = [];

    /** @var list<array{date: string, cents: int, description: string, line: JournalLine}> */
    public array $ledgerLeft = [];

    public int $matched = 0;

    /**
     * @param  list<array{date: string, cents: int, description: string}>  $statement
     */
    public static function run(Account $account, array $statement, string $from, ?string $until = null): self
    {
        $match = new self;
        $statement = array_values(array_filter($statement, fn ($row) => $row['date'] >= $from && (! $until || $row['date'] <= $until)));
        $until ??= collect($statement)->max('date') ?? $from;

        $ledger = JournalLine::query()
            ->with('entry')
            ->where('account_id', $account->id)
            ->whereHas('entry', fn ($q) => $q->whereBetween('entry_date', [$from, $until]))
            ->get()
            ->map(fn (JournalLine $line) => [
                'date' => $line->entry->entry_date->toDateString(),
                'cents' => $line->debit_cents - $line->credit_cents,
                'description' => $line->entry->memo ?? '',
                'line' => $line,
            ])
            ->all();

        [$statement, $ledger] = $match->oneToOne($statement, $ledger);
        [$statement, $ledger] = $match->grouped($statement, $ledger);
        [$ledger, $statement] = $match->grouped($ledger, $statement);

        $match->statementLeft = array_values($statement);
        $match->ledgerLeft = array_values($ledger);

        return $match;
    }

    // Every same-amount pair within MAX_DAYS, the closest taken first.
    private function oneToOne(array $statement, array $ledger): array
    {
        $byAmount = [];
        foreach ($ledger as $j => $row) {
            $byAmount[$row['cents']][] = $j;
        }

        $pairs = [];
        foreach ($statement as $i => $row) {
            foreach ($byAmount[$row['cents']] ?? [] as $j) {
                $days = $this->days($row['date'], $ledger[$j]['date']);
                if ($days <= self::MAX_DAYS) {
                    $pairs[] = [$days, $i, $j];
                }
            }
        }
        sort($pairs);

        foreach ($pairs as [, $i, $j]) {
            if (isset($statement[$i], $ledger[$j])) {
                unset($statement[$i], $ledger[$j]);
                $this->matched++;
            }
        }

        return [$statement, $ledger];
    }

    // Each row of $ones that is the sum of two or more of $many within
    // GROUP_DAYS and with the same sign, the nearest tried first.
    private function grouped(array $ones, array $many): array
    {
        foreach ($ones as $i => $row) {
            $near = array_filter($many, fn ($m) => ($m['cents'] > 0) === ($row['cents'] > 0) && abs($m['cents']) <= abs($row['cents'])
                && $this->days($row['date'], $m['date']) <= self::GROUP_DAYS);
            uasort($near, fn ($a, $b) => $this->days($row['date'], $a['date']) <=> $this->days($row['date'], $b['date']));
            $found = $this->subsetSum(array_map(fn ($m) => abs($m['cents']), array_slice($near, 0, 24, true)), abs($row['cents']));
            if ($found !== null && count($found) > 1) {
                unset($ones[$i]);
                foreach ($found as $j) {
                    unset($many[$j]);
                }
                $this->matched++;
            }
        }

        return [$ones, $many];
    }

    // Keys of $amounts adding up to $target exactly, or null.
    private function subsetSum(array $amounts, int $target): ?array
    {
        $reach = [0 => []];
        foreach ($amounts as $key => $cents) {
            foreach ($reach as $sum => $set) {
                $next = $sum + $cents;
                if ($next <= $target && ! isset($reach[$next])) {
                    $reach[$next] = [...$set, $key];
                    if ($next === $target) {
                        return $reach[$next];
                    }
                }
            }
            if (count($reach) > 200000) {
                return null;
            }
        }

        return null;
    }

    private function days(string $a, string $b): int
    {
        return (int) abs(Carbon::parse($a)->diffInDays(Carbon::parse($b)));
    }

    // ---- Reading statements --------------------------------------------

    // A bank export (Date, Description, Amount, Balance -- Waterford's) or
    // a card export (Transaction Date, Description, Debit, Credit --
    // Capital One's), as rows in the ledger's sign.
    /** @return list<array{date: string, cents: int, description: string, balance: ?int}> */
    public static function rows(array $csvRows): array
    {
        $money = fn (string $value) => (int) round((float) str_replace(['$', ','], '', trim($value)) * 100);

        return array_map(function (array $row) use ($money) {
            if (isset($row['Transaction Date'])) {
                return [
                    'date' => trim($row['Transaction Date']),
                    'cents' => $money($row['Credit'] ?? '') - $money($row['Debit'] ?? ''),
                    'description' => trim($row['Description']),
                    'balance' => null,
                ];
            }

            return [
                'date' => Carbon::createFromFormat('m/d/Y', trim($row['Date']))->toDateString(),
                'cents' => $money($row['Amount']),
                'description' => trim($row['Description']),
                'balance' => trim((string) ($row['Balance'] ?? '')) !== '' ? $money($row['Balance']) : null,
            ];
        }, $csvRows);
    }
}
