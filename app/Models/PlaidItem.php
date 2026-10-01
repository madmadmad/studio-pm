<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// A connected bank (Plaid's "item"). Its charges sync into expenses
// (App\Services\PlaidSync); the access token never leaves the server.
class PlaidItem extends Model
{
    protected $fillable = ['item_id', 'access_token', 'institution_name', 'accounts', 'cursor', 'last_synced_at', 'last_error'];

    protected $hidden = ['access_token', 'cursor'];

    protected $casts = [
        'access_token' => 'encrypted',
        'accounts' => 'array',
        'last_synced_at' => 'datetime',
    ];

    // "Chase ••4521" -- an imported expense's source label.
    public function sourceLabel(?string $accountId): string
    {
        $mask = $this->accounts[$accountId]['mask'] ?? null;

        return trim(($this->institution_name ?: 'Bank').($mask ? " ••{$mask}" : ''));
    }
}
