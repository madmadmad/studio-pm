<?php

namespace App\Notifications;

use App\Models\StudioProfile;
use Illuminate\Notifications\Notification;

// Each January, a nudge in the alerts menu to check the sales tax rate
// invoices charge still matches the state's and county's (CPA review,
// Oct 2026). Sent by the sales-tax:remind command.
class SalesTaxRateCheck extends Notification
{
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $profile = StudioProfile::current();
        $rate = $profile->sales_tax_rate !== null ? rtrim(rtrim((string) $profile->sales_tax_rate, '0'), '.').'%' : 'not set';

        return [
            'message' => 'New year: check the sales tax rate on invoices is still right ('.($profile->sales_tax_name ?: 'Sales tax').", {$rate}).",
            'url' => '/settings?tab='.rawurlencode('Invoices & payments'),
        ];
    }
}
