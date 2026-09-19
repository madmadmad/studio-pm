<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Proposal extends Model
{
    protected $fillable = ['company_id', 'project_id', 'contact_id', 'title', 'body', 'estimate_amount', 'status', 'sent_at', 'accepted_at'];

    protected $casts = [
        'sent_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Proposal $proposal) {
            $proposal->accept_token ??= Str::random(40);
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProposalItem::class);
    }

    public function itemsTotal(): float
    {
        return round($this->items->sum(fn (ProposalItem $item) => $item->amount()), 2);
    }
}
