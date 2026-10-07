<?php

namespace App\Console\Commands;

use App\Support\AvatarProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

// Empties the app of the work entered while trying it out -- clients,
// projects, proposals, invoices, expenses, the ledger -- and keeps the
// setup: super admin accounts, services, studio settings, categories,
// taxes and the chart of accounts. For a clean slate before (and between
// rehearsals of) the Bonsai import.
//
// Shows what it would delete; --force deletes it. Refuses on a production
// environment unless --production is given too.
class ClearTrialData extends Command
{
    protected $signature = 'app:clear-trial-data {--force : Delete, rather than show what would go} {--production : Allow it where APP_ENV is production}';

    protected $description = 'Delete the trial clients, projects, invoices, expenses and ledger, keeping settings, services and the chart of accounts';

    // Children before parents, though foreign keys are off while they go.
    private const TABLES = [
        'journal_lines', 'journal_entries', 'accounting_periods', 'bank_reconciliations',
        'message_reactions', 'message_links', 'message_attachments', 'message_participants', 'messages',
        'expense_splits', 'expenses', 'plaid_dismissals', 'plaid_items',
        'payments', 'transactions', 'invoice_sends', 'invoice_items', 'invoices',
        'proposal_items', 'proposals',
        'time_entries', 'task_files', 'subtasks', 'tasks', 'schedule_items', 'notes',
        'project_favorites', 'project_user', 'projects',
        'contact_magic_links', 'contacts', 'companies',
        'notifications', 'jobs', 'job_batches', 'failed_jobs',
    ];

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('production')) {
            $this->error('This is a production environment. Back up the database, then run again with --production.');

            return self::FAILURE;
        }

        $teamIds = DB::table('users')->where('role', '!=', 'super_admin')->pluck('id');
        $counts = collect(self::TABLES)
            ->filter(fn (string $table) => Schema::hasTable($table))
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])
            ->filter();

        $this->table(['What', 'Rows'], [
            ...$counts->map(fn (int $count, string $table) => [$table, $count])->values(),
            ['users (team members)', $teamIds->count()],
        ]);
        $this->line('Kept: super admin accounts, services, studio settings, categories, taxes, the chart of accounts.');

        if (! $this->option('force')) {
            $this->info('Nothing deleted. Run again with --force to delete these.');

            return self::SUCCESS;
        }

        $files = $this->files($teamIds->all());

        Schema::withoutForeignKeyConstraints(function () use ($counts, $teamIds) {
            DB::transaction(function () use ($counts, $teamIds) {
                foreach ($counts->keys() as $table) {
                    DB::table($table)->delete();
                }
                DB::table('sessions')->whereIn('user_id', $teamIds)->delete();
                DB::table('personal_access_tokens')->where('tokenable_type', 'App\\Models\\User')->whereIn('tokenable_id', $teamIds)->delete();
                DB::table('users')->whereIn('id', $teamIds)->delete();
            });
        });

        // The files went with their records; only once the rows are gone.
        foreach ($files as [$disk, $path]) {
            Storage::disk($disk)->delete($path);
        }

        $this->info(sprintf('Deleted. %d uploaded files removed.', count($files)));

        return self::SUCCESS;
    }

    // [disk, path] of every upload belonging to what's being deleted:
    // receipts, message attachments, task files, team members' photos.
    private function files(array $teamIds): array
    {
        $private = config('filesystems.private_disk');
        $files = [];

        foreach (DB::table('expenses')->whereNotNull('receipt_path')->pluck('receipt_path') as $path) {
            $files[] = [$private, $path];
        }
        foreach (DB::table('message_attachments')->get(['disk', 'path', 'thumbnail_path', 'display_path']) as $file) {
            foreach (array_filter([$file->path, $file->thumbnail_path, $file->display_path]) as $path) {
                $files[] = [$file->disk ?: $private, $path];
            }
        }
        foreach (DB::table('task_files')->pluck('path') as $path) {
            $files[] = ['public', $path];
        }
        foreach (DB::table('users')->whereIn('id', $teamIds)->get(['avatar_path', 'bio_photo_path']) as $user) {
            foreach (array_filter([$user->avatar_path, $user->bio_photo_path]) as $path) {
                $files[] = [$private, $path];
                $files[] = [$private, AvatarProcessor::smallPath($path)];
            }
        }

        return $files;
    }
}
