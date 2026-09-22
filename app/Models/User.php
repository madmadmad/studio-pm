<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'avatar_path'])]
#[Hidden(['password', 'remember_token', 'invite_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    const ROLE_MANAGER = 'manager';

    const ROLE_TEAM_MEMBER = 'team_member';

    // The raw invite_token is hidden from JSON entirely -- this exposes just
    // enough for the Team admin UI to show an "invite pending" state and
    // offer a resend, without ever leaking the token value itself.
    protected $appends = ['has_pending_invite', 'avatar_url'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'invited_at' => 'datetime',
            'invite_expires_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected function getHasPendingInviteAttribute(): bool
    {
        return $this->hasPendingInvite();
    }

    // Never a raw disk URL -- avatars live on the private disk like message
    // attachments do, so this always routes through an authenticated
    // controller action instead.
    protected function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path ? route('avatars.user', $this) : null;
    }

    public function isManager(): bool
    {
        return $this->role === self::ROLE_MANAGER;
    }

    public function isTeamMember(): bool
    {
        return $this->role === self::ROLE_TEAM_MEMBER;
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    public function hasPendingInvite(): bool
    {
        return $this->invite_token !== null;
    }

    /**
     * Every project this user is or was ever assigned to. Filter by
     * wherePivotNull('unassigned_at') for their currently active projects.
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)
            ->withPivot(['assigned_at', 'unassigned_at'])
            ->withTimestamps();
    }

    public function activeProjects(): BelongsToMany
    {
        return $this->projects()->wherePivotNull('unassigned_at');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }
}
