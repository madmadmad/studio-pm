<?php

namespace App\Services\Payroll;

use Illuminate\Support\Carbon;
use Smalot\PdfParser\Parser;

// The payroll provider's (Data Service's) Cash Requirement Summary for one
// pay run, read from its PDF, and the entry it makes.
//
// The report's first part is what Data Service draws from checking: net
// pay, the employees' tax withholdings, the deductions it pays out (the
// HSA), and the employer's taxes, adding up to its TOTAL CASH REQUIREMENT.
// The second, "other items paid by client", is what it doesn't draw: the
// retirement deferrals and employer contributions (American Funds draws
// those itself, around the check date) and the employees' health insurance
// deduction (it stays in checking). Workers' comp at the bottom is an
// estimate paid separately, so it's left out.
//
// Every section is checked against the report's own subtotal and every
// part against its total; a report that doesn't add up -- misread, or a
// layout this doesn't know -- is refused rather than half-entered.
class CashRequirementSummary
{
    // What the entry needs, in cents.
    public int $wages = 0;

    public int $payrollTaxes = 0;

    public int $retirementExpense = 0;

    public int $payrollDebit = 0;

    public int $retirementDebit = 0;

    public int $healthDeduction = 0;

    private function __construct(
        public readonly Carbon $checkDate,
        public readonly ?Carbon $periodDate,
        public readonly ?string $client,
        // [part => [section => ['lines' => [[label, cents]], 'subtotal' => cents]]]
        public readonly array $sections,
        public readonly int $netPayroll,
        public readonly int $totalCashRequirement,
    ) {}

    public static function fromPdf(string $path): self
    {
        try {
            $text = (new Parser)->parseFile($path)->getText();
        } catch (\Throwable) {
            throw new PayrollReportException('That file couldn’t be read as a PDF.');
        }

        return self::fromText($text);
    }

    public static function fromText(string $text): self
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $text)), fn ($l) => $l !== ''));
        $flat = self::key(implode(' ', $lines));

        if (! str_contains($flat, 'CASHREQUIREMENTSUMMARY')) {
            throw new PayrollReportException('This doesn’t look like a Cash Requirement Summary.');
        }
        if (! preg_match('/CHECK\s*DATE:\s*(\d{1,2}\/\d{1,2}\/\d{4})/i', $text, $m)) {
            throw new PayrollReportException('The report’s check date couldn’t be found.');
        }
        $checkDate = Carbon::createFromFormat('m/d/Y', $m[1])->startOfDay();
        $periodDate = preg_match('/PERIOD\s*DATE:\s*(\d{1,2}\/\d{1,2}\/\d{4})/i', $text, $p)
            ? Carbon::createFromFormat('m/d/Y', $p[1])->startOfDay() : null;
        $client = preg_match('/CLIENT\s*(\d+)/i', $text, $c) ? $c[1] : null;

        $part = 'cash';
        $section = null;
        $sections = ['cash' => [], 'other' => []];
        $net = null;
        $totals = [];

        foreach ($lines as $line) {
            $key = self::key($line);
            if (str_starts_with($key, '---')) {
                continue;
            }
            $amount = self::amount($line);
            $label = $amount === null ? $line : trim(preg_replace('/\s*-?[\d,]*\.\d{2}\s*\*?\s*$/', '', $line));
            $labelKey = self::key($label);

            if ($labelKey === 'NETPAYROLL' && $amount !== null) {
                $net = $amount;
            } elseif ($labelKey === 'TOTALCASHREQUIREMENT' && $amount !== null) {
                $totals['cash'] = $amount;
                $part = 'other';
                $section = null;
            } elseif ($labelKey === 'OTHERITEMSPAIDBYCLIENT') {
                if ($amount !== null) {
                    $totals['other'] = $amount;
                }
                $part = 'other';
                $section = null;
            } elseif (in_array($labelKey, ['EMPLOYEETAXWITHHOLDINGS', 'EMPLOYEEDEDUCTIONS', 'EMPLOYERTAXEXPENSES'], true)) {
                $section = $labelKey;
                $sections[$part][$section] = ['lines' => [], 'subtotal' => null];
            } elseif ($section && $amount !== null && $labelKey === '') {
                $sections[$part][$section]['subtotal'] = $amount;
                $section = null;
            } elseif ($section && $amount !== null) {
                $sections[$part][$section]['lines'][] = [$label, $amount];
            }
        }

        if ($net === null || ! isset($totals['cash'])) {
            throw new PayrollReportException('The report’s net payroll or total cash requirement couldn’t be found.');
        }

        // Every section adds up to its subtotal, and each part to its total.
        foreach ($sections as $partName => $partSections) {
            foreach ($partSections as $name => $s) {
                $sum = array_sum(array_column($s['lines'], 1));
                if ($s['subtotal'] === null || $sum !== $s['subtotal']) {
                    throw new PayrollReportException(self::sectionName($partName, $name).' don’t add up to the report’s subtotal. Enter this pay run by hand.');
                }
            }
        }
        $cashSum = $net + array_sum(array_column($sections['cash'], 'subtotal'));
        if ($cashSum !== $totals['cash']) {
            throw new PayrollReportException('The report’s sections don’t add up to its total cash requirement. Enter this pay run by hand.');
        }
        if (isset($totals['other']) && array_sum(array_column($sections['other'], 'subtotal')) !== $totals['other']) {
            throw new PayrollReportException('The “other items paid by client” don’t add up to their total. Enter this pay run by hand.');
        }

        $summary = new self($checkDate, $periodDate, $client, $sections, $net, $totals['cash']);
        $summary->work();

        return $summary;
    }

    // Gross wages are net pay plus everything withheld from it, wherever it
    // was paid; the employer's costs are its taxes and its retirement
    // contributions; checking pays the total cash requirement and the
    // retirement plan; the health deduction goes back against the premiums.
    private function work(): void
    {
        $cash = $this->sections['cash'];
        $otherDeductions = $this->sections['other']['EMPLOYEEDEDUCTIONS']['lines'] ?? [];

        $withheldElsewhere = 0;
        $employerContributions = 0;
        $employeeRetirement = 0;
        foreach ($otherDeductions as [$label, $cents]) {
            $key = self::key($label);
            if (str_contains($key, 'EMPLOYERCONTRIBUTION')) {
                $employerContributions += $cents;
            } elseif (preg_match('/IRA|401K|403B|RETIRE/', $key)) {
                $employeeRetirement += $cents;
                $withheldElsewhere += $cents;
            } elseif (preg_match('/HLTH|HEALTH|MEDICAL|DENTAL|VISION/', $key)) {
                $this->healthDeduction += $cents;
                $withheldElsewhere += $cents;
            } else {
                throw new PayrollReportException("The report has a deduction this doesn’t know how to enter ({$label}). Enter this pay run by hand.");
            }
        }

        $this->wages = $this->netPayroll
            + ($cash['EMPLOYEETAXWITHHOLDINGS']['subtotal'] ?? 0)
            + ($cash['EMPLOYEEDEDUCTIONS']['subtotal'] ?? 0)
            + $withheldElsewhere;
        $this->payrollTaxes = $cash['EMPLOYERTAXEXPENSES']['subtotal'] ?? 0;
        $this->retirementExpense = $employerContributions;
        $this->payrollDebit = $this->totalCashRequirement;
        $this->retirementDebit = $employeeRetirement + $employerContributions;
    }

    // The amounts as the payroll form takes them, in dollars.
    public function amounts(): array
    {
        return collect([
            'wages' => $this->wages,
            'payroll_taxes' => $this->payrollTaxes,
            'retirement_expense' => $this->retirementExpense,
            'payroll_debit' => $this->payrollDebit,
            'retirement_debit' => $this->retirementDebit,
            'health_deduction' => $this->healthDeduction,
        ])->map(fn (int $cents) => number_format($cents / 100, 2, '.', ''))->all();
    }

    // What's kept with the pay run: the dates and every line read.
    public function toArray(): array
    {
        return [
            'check_date' => $this->checkDate->toDateString(),
            'period_date' => $this->periodDate?->toDateString(),
            'client' => $this->client,
            'net_payroll_cents' => $this->netPayroll,
            'total_cash_requirement_cents' => $this->totalCashRequirement,
            'sections' => $this->sections,
        ];
    }

    // "06 HSA 125" and "06HSA125" alike: the PDF's spacing varies.
    private static function key(string $text): string
    {
        return preg_replace('/[^A-Z0-9*\-]/', '', strtoupper($text));
    }

    // The amount a line ends with ("1209.01", ".00", "15490.70 *"), in cents.
    private static function amount(string $line): ?int
    {
        if (! preg_match('/(-?)([\d,]*)\.(\d{2})\s*\*?\s*$/', $line, $m)) {
            return null;
        }

        return ($m[1] === '-' ? -1 : 1) * ((int) str_replace(',', '', $m[2]) * 100 + (int) $m[3]);
    }

    private static function sectionName(string $part, string $section): string
    {
        $name = ['EMPLOYEETAXWITHHOLDINGS' => 'Employee tax withholdings', 'EMPLOYEEDEDUCTIONS' => 'Employee deductions', 'EMPLOYERTAXEXPENSES' => 'Employer tax expenses'][$section];

        return $part === 'other' ? "{$name} (other items paid by client)" : $name;
    }
}
