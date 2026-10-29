<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

// "Time to reconcile Capital One Card (last: Sep 15)" in the alerts menu,
// sent by the reconcile:remind command.
class ReconciliationDue extends Notification
{
    /** @param list<array{name: string, since: ?string}> $accounts */
    public function __construct(public array $accounts) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $names = collect($this->accounts)->map(fn (array $a) => $a['name'].($a['since'] ? ' (last: '.Carbon::parse($a['since'])->format('M j').')' : ' (never)'));

        return [
            'message' => 'Time to reconcile '.$names->join(', ', ' and ').'.',
            'url' => '/bookkeeping/reconcile',
        ];
    }
}
