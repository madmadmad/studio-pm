<?php

namespace App\Console\Commands;

use App\Models\PlaidItem;
use App\Services\PlaidSync;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;

// The daily bank feed: every connected bank's new charges into expenses
// (the Expenses page's Sync now does the same on demand).
class SyncPlaidTransactions extends Command
{
    protected $signature = 'plaid:sync';

    protected $description = 'Import new charges from connected banks as expenses.';

    public function handle(PlaidSync $syncer): void
    {
        PlaidItem::each(function (PlaidItem $item) use ($syncer) {
            try {
                $result = $syncer->sync($item);
                $this->info("{$item->institution_name}: {$result['added']} added, {$result['updated']} updated, {$result['removed']} removed");
            } catch (RequestException $e) {
                $this->error("{$item->institution_name}: {$item->fresh()->last_error}");
            }
        });
    }
}
