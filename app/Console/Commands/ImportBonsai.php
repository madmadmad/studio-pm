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
class ImportBonsai extends Command
{
    protected $signature = 'bonsai:import
        {--expenses= : Bonsai\'s expense CSV export}
        {--invoices= : Bonsai\'s invoice CSV export}
        {--items= : The invoice line items pulled through the Bonsai connector (JSON Lines)}
        {--from='.BonsaiRules::FROM.' : Leave out anything before this date}
        {--force : Import for real (back up the database first)}';

    protected $description = 'Import the Bonsai history (a dry run unless --force)';

    public function handle(BonsaiImport $import): int
    {
        foreach (['expenses', 'invoices', 'items'] as $option) {
            if (! $this->option($option) || ! is_readable($this->option($option))) {
                $this->error("--{$option} needs a readable file.");

                return self::FAILURE;
            }
        }

        $commit = (bool) $this->option('force');
        $this->info($commit ? 'Importing…' : 'Dry run: nothing will be saved.');

        $report = $import->run($this->option('expenses'), $this->option('invoices'), $this->option('items'), $this->option('from'), $commit);

        $path = 'bonsai-import/report-'.now()->format('Y-m-d-His').($commit ? '' : '-dry-run').'.txt';
        Storage::disk('local')->put($path, $report->render(full: true));

        $this->line($report->render(full: false));
        $this->info('Full report: '.Storage::disk('local')->path($path));
        $this->info($commit ? 'Imported.' : 'Dry run finished; nothing was saved. Run again with --force to import.');

        return self::SUCCESS;
    }
}
