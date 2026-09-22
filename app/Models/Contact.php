<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;

#[Hidden(['remember_token'])]
class Contact extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait, Notifiable;

    protected $fillable = ['company_id', 'name', 'email', 'phone', 'role', 'is_primary', 'is_billing', 'avatar_path'];

    protected $appends = ['has_portal_access', 'avatar_url'];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_billing' => 'boolean',
        'portal_invited_at' => 'datetime',
        'remember_token_issued_at' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function magicLinks(): HasMany
    {
        return $this->hasMany(ContactMagicLink::class);
    }

    public function hasPortalAccess(): bool
    {
        return $this->portal_invited_at !== null;
    }

    protected function getHasPortalAccessAttribute(): bool
    {
        return $this->hasPortalAccess();
    }

    // Never a raw disk URL -- see User::getAvatarUrlAttribute() for why.
    protected function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path ? route('avatars.contact', $this) : null;
    }

    // Client access is whole-company, not per-project -- every contact
    // invited to the portal sees every project their company has.
    public function visibleProjects()
    {
        return Project::where('company_id', $this->company_id);
    }

    public function canAccessProject(Project $project): bool
    {
        return $this->hasPortalAccess() && $this->company_id === $project->company_id;
    }
}
