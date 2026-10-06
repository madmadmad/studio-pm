<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Receipts used to be stored on the public disk, reachable by anyone with
// the URL. This moves each one to the private disk at the same path (so the
// rows don't change); one already moved, or missing, is skipped.
return new class extends Migration
{
    public function up(): void
    {
        $public = Storage::disk('public');
        $private = Storage::disk(config('filesystems.private_disk'));

        DB::table('expenses')->whereNotNull('receipt_path')->pluck('receipt_path')->each(function (string $path) use ($public, $private) {
            if (! $public->exists($path)) {
                return;
            }
            if (! $private->exists($path)) {
                $private->writeStream($path, $public->readStream($path));
            }
            $public->delete($path);
        });
    }

    public function down(): void
    {
        // Not moved back: receipts shouldn't be public.
    }
};
