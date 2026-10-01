<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// What an invoice is for, beyond project work (Hosting...). An invoice with
// no category is project work -- PROJECT_WORK is how that reads.
class InvoiceCategory extends Model
{
    public const PROJECT_WORK = 'Project work';

    protected $fillable = ['name'];

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'category_id');
    }
}
