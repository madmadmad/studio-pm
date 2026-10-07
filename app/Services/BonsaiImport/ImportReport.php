<?php

namespace App\Services\BonsaiImport;

// What an import run did, or would do: counts, what was skipped and why,
// what a person should look over, and the ledger's totals afterwards.
class ImportReport
{
    /** @var array<string, int> */
    public array $counts = [];

    /** @var array<string, list<string>> */
    public array $skipped = [];

    /** @var array<string, list<string>> */
    public array $review = [];

    /** @var list<string> */
    public array $ledger = [];

    public function count(string $what, int $by = 1): void
    {
        $this->counts[$what] = ($this->counts[$what] ?? 0) + $by;
    }

    public function skip(string $reason, string $line): void
    {
        $this->skipped[$reason][] = $line;
    }

    public function review(string $topic, string $line): void
    {
        $this->review[$topic][] = $line;
    }

    public function render(bool $full): string
    {
        $out = ['Bonsai import', str_repeat('=', 13), ''];

        $out[] = 'Brought in';
        foreach ($this->counts as $what => $n) {
            $out[] = sprintf('  %-46s %6d', $what, $n);
        }

        foreach (['Skipped' => $this->skipped, 'To look over' => $this->review] as $heading => $groups) {
            $out[] = '';
            $out[] = $heading;
            if ($groups === []) {
                $out[] = '  (none)';
            }
            foreach ($groups as $topic => $lines) {
                $out[] = sprintf('  %s: %d', $topic, count($lines));
                foreach ($full ? $lines : [] as $line) {
                    $out[] = '    '.$line;
                }
            }
        }

        $out[] = '';
        $out[] = 'Ledger afterwards';
        foreach ($this->ledger as $line) {
            $out[] = '  '.$line;
        }

        return implode("\n", $out)."\n";
    }
}
