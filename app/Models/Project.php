<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Project extends Model
{
    protected $fillable = ['company_id', 'contact_id', 'name', 'po_number', 'description', 'status', 'budget', 'team_names'];

    protected $casts = [
        'team_names' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Everyone ever assigned to this project. Filter by
     * wherePivotNull('unassigned_at') for the current roster.
     */
    // Everyone who has starred this project.
    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_favorites')->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['assigned_at', 'unassigned_at'])
            ->withTimestamps();
    }

    public function activeUsers(): BelongsToMany
    {
        return $this->users()->wherePivotNull('unassigned_at');
    }

    /**
     * True if the given user has ever been assigned here -- used to grant
     * read-only access to past work after they're taken off the project.
     */
    public function everHadUser(User $user): bool
    {
        return $this->relationLoaded('users')
            ? $this->users->contains('id', $user->id)
            : $this->users()->whereKey($user->id)->exists();
    }

    public function currentlyHasUser(User $user): bool
    {
        return $this->activeUsers()->whereKey($user->id)->exists();
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    // Hours sold on this project: the quantities of hourly-service line
    // items across its accepted proposals. Fixed-price and custom lines
    // aren't counted -- their quantities aren't hours.
    public function proposedHours(): float
    {
        return (float) ProposalItem::query()
            ->whereHas('proposal', fn ($q) => $q->where('project_id', $this->id)->where('status', 'accepted'))
            ->whereHas('service', fn ($q) => $q->where('unit', 'hourly'))
            ->sum('quantity');
    }

    // In the order the studio dragged them into (the list and the gantt
    // chart both read top to bottom this way).
    public function scheduleItems(): HasMany
    {
        return $this->hasMany(ScheduleItem::class)->orderBy('position')->orderBy('id');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    // Every line of every invoice on the project -- for summing what's
    // been billed (before tax).
    public function invoiceItems(): HasManyThrough
    {
        return $this->hasManyThrough(InvoiceItem::class, Invoice::class);
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class)->latest();
    }

    // Root messages only (threads) -- replies are nested under each via
    // Message::replies(). Oldest first so a thread reads top-to-bottom.
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->whereNull('parent_id')->oldest('sent_at');
    }
}
