<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// What an invoice is for, beyond project work (Hosting...). An invoice with
// no category is project work -- PROJECT_WORK is how that reads.
class InvoiceCategory extends Model
{
    public const PROJECT_WORK = 'Project work';

    protected $fillable = ['name', 'revenue_account_id'];

    // Where its invoices' income posts, when a line doesn't say otherwise.
    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'revenue_account_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'category_id');
    }
}
