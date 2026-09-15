<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProposalItem extends Model
{
    protected $fillable = ['proposal_id', 'service_id', 'description', 'details', 'quantity', 'rate'];

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function amount(): float
    {
        return round((float) $this->quantity * (float) $this->rate, 2);
    }
}
