<?php

namespace App\Services\Reconciliation\Statements;

use App\Exceptions\LedgerException;
use Illuminate\Support\Carbon;

// Capital One's monthly business card statement, as its PDF's text reads:
//
//   Previous Balance  $10,668.55       Payments  - $10,668.55
//   Other Credits  - $14.26            Transactions  + $17,557.87
//   Cash Advances  + $0.00             Fees Charged  + $0.00
//   Interest Charged  + $0.00          New Balance  = $17,543.61
//   ... for Business ending in 7035
//   Aug 03, 2026 - Sep 02, 2026  |  31 days in Billing Cycle
//   WILLIAM M SATTLER #7035: Payments, Credits and Adjustments
//   Aug 24 Aug 24 CAPITAL ONE ONLINE PYMT  - $10,668.55
//   WILLIAM M SATTLER #7035: Transactions
//   Aug 1 Aug 3 Amazon web services SEATTLE WA  $395.16
//   WILLIAM M SATTLER #7035: Total Transactions  $12,611.23
//   ... each cardholder in turn, then Fees and Interest Charged.
//
// Each line is dated by when it was made (its trans date, as the card's
// CSV export dates it), the year taken from the billing cycle -- so a
// charge made a day or two before the cycle began, posted in it, is on
// this statement. A "- $" amount is a payment or credit (less owed), the
// rest are charges. Every cardholder's charges are checked against their
// total, and the whole against the account summary.
class CapitalOneStatement
{
    private const MONTHS = 'Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec';

    private const AMOUNT = '([\d,]+\.\d{2})';

    public static function read(string $text): BankStatement
    {
        $find = fn (string $pattern) => preg_match($pattern, $text, $m) ? $m : null;
        $summary = fn (string $label, string $op) => ($m = $find('/'.$label.'\s*'.$op.'\s*\$'.self::AMOUNT.'/')) ? BankStatement::cents($m[1]) : null;

        $account = $find('/ending in (\d{4})/');
        $period = $find('/('.self::MONTHS.') (\d{2}), (\d{4}) - ('.self::MONTHS.') (\d{2}), (\d{4})\s*\|\s*\d+ days in Billing Cycle/');
        $figures = [
            'previous' => $summary('Previous Balance', ''),
            'payments' => $summary('Payments', '-'),
            'credits' => $summary('Other Credits', '-'),
            'transactions' => $summary('Transactions', '\+'),
            'cash' => $summary('Cash Advances', '\+'),
            'fees' => $summary('Fees Charged', '\+'),
            'interest' => $summary('Interest Charged', '\+'),
            'new' => $summary('New Balance', '='),
        ];
        if (! $account || ! $period || in_array(null, $figures, true)) {
            throw new LedgerException('That Capital One PDF doesn’t have the account summary this reads (previous and new balance, payments, credits and transactions).');
        }
        $start = Carbon::createFromFormat('M d Y', "{$period[1]} {$period[2]} {$period[3]}")->startOfDay();
        $end = Carbon::createFromFormat('M d Y', "{$period[4]} {$period[5]} {$period[6]}")->startOfDay();

        $rows = [];
        $kinds = [];
        $holderTotals = [];
        $holder = null;
        $section = null;
        $pending = null;
        foreach (preg_split('/\R/', $text) as $raw) {
            $line = trim(preg_replace('/\s+/', ' ', str_replace("\t", ' ', $raw)));

            if (preg_match('/#(\d{4}): (Payments, Credits and Adjustments|Transactions)$/', $line, $m)) {
                [$holder, $section, $pending] = [$m[1], $m[2] === 'Transactions' ? 'charges' : 'credits', null];

                continue;
            }
            if (preg_match('/#(\d{4}): Total Transactions \$'.self::AMOUNT.'$/', $line, $m)) {
                $holderTotals[$m[1]] = BankStatement::cents($m[2]);
                $section = null;

                continue;
            }
            if ($line === 'Fees') {
                [$holder, $section, $pending] = [null, 'fees', null];

                continue;
            }
            if (in_array($line, ['Interest Charged', 'Totals Year-to-Date', 'Interest Charge Calculation'], true) || str_starts_with($line, 'Total Transactions for This Period')) {
                [$section, $pending] = [null, null];

                continue;
            }
            if (! $section) {
                continue;
            }

            // "Aug 1 Aug 3 Amazon web services SEATTLE WA $395.16", or the
            // rest of one that wrapped.
            if (preg_match('/^(('.self::MONTHS.') \d{1,2}) ('.self::MONTHS.') \d{1,2} (.*)$/', $line, $m)) {
                $pending = ['date' => $m[1], 'text' => $m[4]];
            } elseif ($pending) {
                $pending['text'] .= ' '.$line;
            } else {
                continue;
            }
            if (preg_match('/^(.*?)\s*(-\s*)?\$'.self::AMOUNT.'$/', $pending['text'], $m)) {
                $cents = BankStatement::cents($m[3]);
                $rows[] = [
                    'date' => self::date($pending['date'], $end),
                    // Less owed is money in to the card (a debit, positive).
                    'cents' => $m[2] !== '' ? $cents : -$cents,
                    'description' => trim($m[1]),
                ];
                $kinds[] = [$section, $holder];
                $pending = null;
            }
        }

        // Interest is a figure, not a line: dated the cycle's last day.
        if ($figures['interest'] > 0) {
            $rows[] = ['date' => $end->toDateString(), 'cents' => -$figures['interest'], 'description' => 'Interest charged'];
            $kinds[] = ['interest', null];
        }

        self::check($rows, $kinds, $holderTotals, $figures);

        $in = collect($rows)->where('cents', '>', 0);
        $out = collect($rows)->where('cents', '<', 0);

        return new BankStatement(
            bank: 'Capital One',
            accountKey: 'capital_one_card',
            accountLast4: $account[1],
            periodStart: $start->toDateString(),
            statementDate: $end->toDateString(),
            beginningCents: $figures['previous'],
            endingCents: $figures['new'],
            rows: collect($rows)->sortBy('date')->values()->all(),
            dailyBalances: [],
            totals: [
                'in_count' => $in->count(), 'in_cents' => $figures['payments'] + $figures['credits'],
                'out_count' => $out->count(), 'out_cents' => $figures['transactions'] + $figures['cash'] + $figures['fees'] + $figures['interest'],
            ],
        );
    }

    // What was read has to come to the statement's own figures: each
    // cardholder's charges to their total, the payments and credits, the
    // charges and fees to the summary's, and the summary to the new balance.
    private static function check(array $rows, array $kinds, array $holderTotals, array $f): void
    {
        $money = fn (int $cents) => '$'.number_format($cents / 100, 2);
        $refuse = fn (string $what) => throw new LedgerException("{$what} Upload the card’s CSV export instead, or enter the balance by hand.");
        $sum = function (callable $which) use ($rows, $kinds) {
            $total = 0;
            foreach ($rows as $i => $row) {
                if ($which(...$kinds[$i])) {
                    $total += $row['cents'];
                }
            }

            return $total;
        };

        foreach ($holderTotals as $holder => $total) {
            // (An array key of digits comes back an int.)
            $holder = (string) $holder;
            $read = -$sum(fn ($section, $h) => $section === 'charges' && $h === $holder);
            if ($read !== $total) {
                $refuse("Read {$money($read)} of charges on the card ending {$holder}, but the statement says {$money($total)}.");
            }
        }
        $credits = $sum(fn ($section) => $section === 'credits');
        if ($credits !== $f['payments'] + $f['credits']) {
            $refuse("Read {$money($credits)} of payments and credits, but the statement says {$money($f['payments'] + $f['credits'])}.");
        }
        $charges = -$sum(fn ($section) => $section === 'charges');
        if ($charges !== $f['transactions'] + $f['cash']) {
            $refuse("Read {$money($charges)} of charges, but the statement says {$money($f['transactions'] + $f['cash'])}.");
        }
        $fees = -$sum(fn ($section) => $section === 'fees');
        if ($fees !== $f['fees']) {
            $refuse("Read {$money($fees)} of fees, but the statement says {$money($f['fees'])}.");
        }
        if ($f['previous'] - $f['payments'] - $f['credits'] + $f['transactions'] + $f['cash'] + $f['fees'] + $f['interest'] !== $f['new']) {
            $refuse('The statement’s account summary doesn’t come to its new balance as read.');
        }
    }

    // "Aug 1" in the cycle ending $end: that year, or the one before for a
    // December charge on a cycle that ends in January.
    private static function date(string $monthDay, Carbon $end): string
    {
        $date = Carbon::createFromFormat('M j Y', "{$monthDay} {$end->year}")->startOfDay();

        return ($date->gt($end->copy()->addDays(7)) ? $date->subYear() : $date)->toDateString();
    }
}
