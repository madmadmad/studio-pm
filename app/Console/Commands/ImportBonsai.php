<?php

namespace App\Console\Commands;

use App\Services\BonsaiImport\BonsaiImport;
use App\Services\BonsaiImport\BonsaiRules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

// The Bonsai history into the app (docs/ledger-plan.md, Phase 6). A dry
// run unless --force: it does everything, ledger included, reports, and
// rolls it all back. The full report (client names and all) is saved
// under storage/app/private/bonsai-import, which git ignores.
//
// On a server with no files of its own (Laravel Cloud), --disk reads the
// files from that storage disk instead (paths on it, e.g. the private
// bucket), and saves the full report there too.
class ImportBonsai extends Command
{
    protected $signature = 'bonsai:import
        {--expenses= : Bonsai\'s expense CSV export}
        {--invoices= : Bonsai\'s invoice CSV export}
        {--items= : The invoice line items pulled through the Bonsai connector (JSON Lines)}
        {--from='.BonsaiRules::FROM.' : Leave out anything before this date}
        {--bank= : Checking\'s export from the bank: card payments come from it, and the ledger is matched against it (optional)}
        {--card=* : The card\'s exports, matched against the ledger (optional, one --card for each file)}
        {--opening-checking= : Checking\'s balance the day before --from}
        {--opening-card= : What the Capital One card owed the day before --from}
        {--disk= : Read the files from this storage disk (paths on it) and save the report there}
        {--force : Import for real (back up the database first)}';

    protected $description = 'Import the Bonsai history (a dry run unless --force)';

    // Paths given with --disk, fetched to local temp files for the run.
    private array $fetched = [];

    public function handle(BonsaiImport $import): int
    {
        try {
            return $this->import($import);
        } finally {
            foreach ($this->fetched as $file) {
                @unlink($file);
            }
        }
    }

    // A file option's path on this machine: as given, or fetched from
    // --disk to a temp file.
    private function file(?string $path): ?string
    {
        if (! $path || ! $this->option('disk')) {
            return $path;
        }

        $disk = Storage::disk($this->option('disk'));
        if (! $disk->exists($path)) {
            return null;
        }
        $local = tempnam(sys_get_temp_dir(), 'bonsai-');
        file_put_contents($local, $disk->get($path));

        return $this->fetched[] = $local;
    }

    private function import(BonsaiImport $import): int
    {
        $files = [];
        foreach (['expenses', 'invoices', 'items', 'bank'] as $option) {
            $files[$option] = $this->file($this->option($option));
        }
        $cards = array_map(fn ($card) => $this->file($card), $this->option('card'));

        foreach (['expenses', 'invoices', 'items'] as $option) {
            if (! $files[$option] || ! is_readable($files[$option])) {
                $this->error("--{$option} needs a readable file.");

                return self::FAILURE;
            }
        }

        $commit = (bool) $this->option('force');
        $this->info($commit ? 'Importing…' : 'Dry run: nothing will be saved.');

        if ($this->option('bank') && ! $files['bank']) {
            $this->error("Can't read {$this->option('bank')}.");

            return self::FAILURE;
        }
        foreach ($this->option('card') as $i => $card) {
            if (! $cards[$i] || ! is_readable($cards[$i])) {
                $this->error("Can't read {$card}.");

                return self::FAILURE;
            }
        }
        $opening = array_filter(['checking' => $this->option('opening-checking'), 'capital_one_card' => $this->option('opening-card')]);

        $report = $import->run($files['expenses'], $files['invoices'], $files['items'], $this->option('from'), $commit, $files['bank'], $opening, $cards);

        $path = 'bonsai-import/report-'.now()->format('Y-m-d-His').($commit ? '' : '-dry-run').'.txt';
        $reportDisk = Storage::disk($this->option('disk') ?: 'local');
        $reportDisk->put($path, $report->render(full: true));

        $this->line($report->render(full: false));
        $this->info('Full report: '.($this->option('disk') ? "{$path} on the {$this->option('disk')} disk" : $reportDisk->path($path)));
        $this->info($commit ? 'Imported.' : 'Dry run finished; nothing was saved. Run again with --force to import.');

        return self::SUCCESS;
    }
}
