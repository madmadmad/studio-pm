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

#[Fillable(['name', 'job_title', 'bio', 'bio_photo_path', 'email', 'password', 'role', 'avatar_path'])]
#[Hidden(['password', 'remember_token', 'invite_token', 'avatar_path', 'bio_photo_path'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    // Everything, including Settings and the team (config/permissions.php).
    const ROLE_SUPER_ADMIN = 'super_admin';

    // Assigned projects, plus whatever permissions they've been given.
    const ROLE_TEAM_MEMBER = 'team_member';

    // The raw invite_token is hidden from JSON entirely -- this exposes just
    // enough for the Team admin UI to show an "invite pending" state and
    // offer a resend, without ever leaking the token value itself.
    protected $appends = ['has_pending_invite', 'avatar_url', 'bio_photo_url'];

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
            'permissions' => 'array',
        ];
    }

    protected function getHasPendingInviteAttribute(): bool
    {
        return $this->hasPendingInvite();
    }

    // Never a raw disk URL -- avatars live on the private disk like message
    // attachments do, so this always routes through an authenticated
    // controller action instead.
    // The headshot for proposals' team sections -- separate from the
    // avatar, private like it.
    protected function getBioPhotoUrlAttribute(): ?string
    {
        return $this->bio_photo_path ? route('bio-photos.user', $this) : null;
    }

    protected function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path ? route('avatars.user', $this) : null;
    }

    // A new bio photo in place of the old one (null to remove it).
    public function replaceBioPhoto(?\Illuminate\Http\UploadedFile $file): self
    {
        $old = $this->bio_photo_path;
        $this->update(['bio_photo_path' => $file
            ? \App\Support\AvatarProcessor::store($file, \App\Support\AvatarProcessor::BIO_PHOTO_WIDTH, 'bio-photos', \App\Support\AvatarProcessor::BIO_PHOTO_HEIGHT)
            : null]);
        \App\Support\AvatarProcessor::delete($old);

        return $this->fresh();
    }

    // A bio as saved: the editor's HTML cut down to its own safe tags, or
    // nothing when it's blank.
    public static function cleanBio(?string $bio): ?string
    {
        return \App\Support\RichText::isBlank($bio) ? null : \App\Support\RichText::toSafeHtml($bio);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    // May this person do it (config/permissions.php)? A super admin may do
    // anything; a team member only what they've been granted -- and never
    // the super-admin-only ones.
    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return ! in_array($permission, config('permissions.super_admin_only'), true)
            && in_array($permission, $this->permissions ?? [], true);
    }

    // Active staff who have a permission: super admins, and team members
    // it's been granted to (who to alert about a failed invoice send, ...).
    public static function withPermission(string $permission)
    {
        return static::whereNull('deactivated_at')
            ->where(fn ($q) => $q->where('role', self::ROLE_SUPER_ADMIN)
                ->when(! in_array($permission, config('permissions.super_admin_only'), true), fn ($q) => $q
                    ->orWhereJsonContains('permissions', $permission)))
            ->get();
    }

    // Every permission this person has, for the front end (auth.user).
    public function effectivePermissions(): array
    {
        return collect([...array_keys(config('permissions.grantable')), ...config('permissions.super_admin_only')])
            ->filter(fn (string $p) => $this->hasPermission($p))
            ->values()->all();
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

    // The projects this person has starred (ProjectFavoriteController).
    public function favoriteProjects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_favorites')->withTimestamps();
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }
}
