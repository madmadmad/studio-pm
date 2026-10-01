<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// A bank charge deleted from the expense list: syncs skip it from then on.
class PlaidDismissal extends Model
{
    protected $fillable = ['transaction_id'];
}
