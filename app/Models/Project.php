<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class)->latest();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->latest('sent_at');
    }
}
