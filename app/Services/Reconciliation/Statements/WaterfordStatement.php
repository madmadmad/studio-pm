<?php

namespace App\Services\Reconciliation\Statements;

use App\Exceptions\LedgerException;
use Illuminate\Support\Carbon;

// Waterford Bank's monthly checking statement, as its PDF's text reads:
//
//   Waterford Business Analysis Checking  -  XXXXXXXX6270
//   09/01/2026 Beginning Balance        $323,150.01
//   15 Credit(s) This Period            $39,666.65
//   25 Debit(s) This Period             $66,722.28
//   09/30/2026 Ending Balance           $296,094.38
//   Credits / Debits                    09/01/2026ANDERSONS ...   $380.00
//   Checks Cleared                      8670 09/23/2026 $50.00
//   Daily Balances                      09/01/2026   $327,430.01
//
// A transaction's date runs into its description, and a long one wraps,
// its amount a line or two down. The page footers and the back of page
// one's small print never start with a date, so they're passed over.
// Checks count as debits.
class WaterfordStatement
{
    private const DATE = '(\d{2}\/\d{2}\/\d{4})';

    private const AMOUNT = '\$([\d,]+\.\d{2})';

    public static function read(string $text): BankStatement
    {
        $lines = preg_split('/\R/', $text);
        $find = function (string $pattern) use ($text): ?array {
            return preg_match($pattern, $text, $m) ? $m : null;
        };

        $account = $find('/Checking\s+-\s+X*(\d{4})/');
        $beginning = $find('/'.self::DATE.'\s*Beginning Balance\s*'.self::AMOUNT.'/');
        $ending = $find('/'.self::DATE.'\s*Ending Balance\s*'.self::AMOUNT.'/');
        $credits = $find('/(\d+)\s+Credit\(s\) This Period\s*'.self::AMOUNT.'/');
        $debits = $find('/(\d+)\s+Debit\(s\) This Period\s*'.self::AMOUNT.'/');
        if (! $account || ! $beginning || ! $ending || ! $credits || ! $debits) {
            throw new LedgerException('That Waterford PDF doesn’t have the account summary this reads (beginning and ending balance, credits and debits).');
        }

        $rows = [];
        $balances = [];
        $section = null;
        $pending = null;
        foreach ($lines as $raw) {
            $line = trim(str_replace("\t", ' ', $raw));
            $heading = preg_replace('/\s*\(continued\)$/', '', $line);
            if (in_array($heading, ['Credits', 'Debits', 'Checks Cleared', 'Daily Balances'], true)) {
                $section = $heading;
                $pending = null;

                continue;
            }
            if ($line === '' || self::isFooter($line)) {
                continue;
            }

            if ($section === 'Credits' || $section === 'Debits') {
                // A new transaction, or the rest of a wrapped one.
                if (preg_match('/^'.self::DATE.'(.*)$/', $line, $m)) {
                    $pending = ['date' => $m[1], 'text' => $m[2]];
                } elseif ($pending) {
                    $pending['text'] .= ' '.$line;
                } else {
                    continue;
                }
                if (preg_match('/^(.*?)\s*'.self::AMOUNT.'$/', $pending['text'], $m)) {
                    $cents = BankStatement::cents($m[2]);
                    $rows[] = [
                        'date' => self::date($pending['date']),
                        'cents' => $section === 'Credits' ? $cents : -$cents,
                        'description' => preg_replace('/\s+/', ' ', trim($m[1])) ?: ($section === 'Credits' ? 'Deposit' : 'Debit'),
                    ];
                    $pending = null;
                }
            } elseif ($section === 'Checks Cleared') {
                // One or more "8670 09/23/2026 $50.00" to a line ("8672*" skips a number).
                preg_match_all('/(\d+)\*?\s+'.self::DATE.'\s+'.self::AMOUNT.'/', $line, $all, PREG_SET_ORDER);
                foreach ($all as $m) {
                    $rows[] = ['date' => self::date($m[2]), 'cents' => -BankStatement::cents($m[3]), 'description' => "Check {$m[1]}"];
                }
            } elseif ($section === 'Daily Balances') {
                preg_match_all('/'.self::DATE.'\s*\$?(-?[\d,]+\.\d{2})/', $line, $all, PREG_SET_ORDER);
                foreach ($all as $m) {
                    $balances[self::date($m[1])] = BankStatement::cents($m[2]);
                }
            }
        }

        $statement = new BankStatement(
            bank: 'Waterford',
            accountKey: 'checking',
            accountLast4: $account[1],
            periodStart: self::date($beginning[1]),
            statementDate: self::date($ending[1]),
            beginningCents: BankStatement::cents($beginning[2]),
            endingCents: BankStatement::cents($ending[2]),
            rows: collect($rows)->sortBy('date')->values()->all(),
            dailyBalances: $balances,
            totals: [
                'in_count' => (int) $credits[1], 'in_cents' => BankStatement::cents($credits[2]),
                'out_count' => (int) $debits[1], 'out_cents' => BankStatement::cents($debits[2]),
            ],
        );
        self::check($statement);

        return $statement;
    }

    // What was read has to come to what the statement says: as many
    // credits and debits, for the same totals, and the beginning balance
    // plus the one less the other to the ending balance.
    private static function check(BankStatement $s): void
    {
        $in = collect($s->rows)->where('cents', '>', 0);
        $out = collect($s->rows)->where('cents', '<', 0);
        $money = fn (int $cents) => '$'.number_format($cents / 100, 2);

        if ($in->count() !== $s->totals['in_count'] || $in->sum('cents') !== $s->totals['in_cents']) {
            throw new LedgerException("Read {$in->count()} credits for {$money($in->sum('cents'))}, but the statement says {$s->totals['in_count']} for {$money($s->totals['in_cents'])}. Upload its CSV export instead, or enter the balance by hand.");
        }
        if ($out->count() !== $s->totals['out_count'] || -$out->sum('cents') !== $s->totals['out_cents']) {
            throw new LedgerException("Read {$out->count()} debits and checks for {$money(-$out->sum('cents'))}, but the statement says {$s->totals['out_count']} for {$money($s->totals['out_cents'])}. Upload its CSV export instead, or enter the balance by hand.");
        }
        if ($s->beginningCents + $s->totals['in_cents'] - $s->totals['out_cents'] !== $s->endingCents) {
            throw new LedgerException('The statement’s beginning balance, credits and debits don’t come to its ending balance as read. Upload its CSV export instead, or enter the balance by hand.');
        }
    }

    private static function isFooter(string $line): bool
    {
        return (bool) preg_match('/^(Statement Ending \d{2}\/\d{2}\/\d{4}|Page \d+ of \d+|[0-9A-F]{32}\b|Date\s+(Description\s+)?Amount$|Check Nbr Date Amount|\* Indicates skipped)/', $line);
    }

    private static function date(string $mdy): string
    {
        return Carbon::createFromFormat('m/d/Y', $mdy)->toDateString();
    }
}
