<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\AvatarProcessor;
use App\Support\RichText;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

#[Fillable(['name', 'job_title', 'bio', 'bio_photo_path', 'email', 'password', 'role', 'avatar_path'])]
#[Hidden(['password', 'remember_token', 'invite_token', 'login_token', 'two_factor_secret', 'two_factor_recovery_codes', 'avatar_path', 'bio_photo_path'])]
// Passport's HasApiTokens, not Sanctum's (the two can't share a model): the
// MCP server's OAuth tokens (routes/ai.php). Sanctum still signs the app's
// own pages in to /api by session cookie, which needs no tokens.
class User extends Authenticatable implements OAuthenticatable, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    // Everything, including Settings and the team (config/permissions.php).
    const ROLE_SUPER_ADMIN = 'super_admin';

    // Assigned projects, plus whatever permissions they've been given.
    const ROLE_TEAM_MEMBER = 'team_member';

    // The studio's outside accountant (CPA): not staff. Bookkeeping, and
    // invoices and expenses to look at -- read-only, except that they may
    // post and reverse journal entries (adjustments). Signs in by emailed
    // link, never a password; everything else is closed to them
    // (RestrictAccountant).
    const ROLE_ACCOUNTANT = 'accountant';

    // What an accountant sees, whatever their permissions column says.
    const ACCOUNTANT_PERMISSIONS = ['bookkeeping', 'invoices', 'expenses'];

    // The raw invite_token is hidden from JSON entirely -- this exposes just
    // enough for the Team admin UI to show an "invite pending" state and
    // offer a resend, without ever leaking the token value itself.
    protected $appends = ['has_pending_invite', 'avatar_url', 'bio_photo_url', 'two_factor_enabled'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'notify_on_document_open' => 'boolean',
            'invited_at' => 'datetime',
            'invite_expires_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'login_token_expires_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
            'permissions' => 'array',
        ];
    }

    // Two-factor set up (required of staff) -- the Team list shows it.
    protected function getTwoFactorEnabledAttribute(): bool
    {
        return $this->two_factor_confirmed_at !== null;
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
        return $this->bio_photo_path ? route('bio-photos.user', [$this, 'v' => AvatarProcessor::version($this->bio_photo_path)]) : null;
    }

    protected function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path ? route('avatars.user', [$this, 'v' => AvatarProcessor::version($this->avatar_path)]) : null;
    }

    // A new bio photo in place of the old one (null to remove it).
    public function replaceBioPhoto(?UploadedFile $file): self
    {
        $old = $this->bio_photo_path;
        $this->update(['bio_photo_path' => $file
            ? AvatarProcessor::store($file, AvatarProcessor::BIO_PHOTO_WIDTH, 'bio-photos', AvatarProcessor::BIO_PHOTO_HEIGHT, AvatarProcessor::BIO_PHOTO_SMALL_WIDTH)
            : null]);
        AvatarProcessor::delete($old);

        return $this->fresh();
    }

    // A bio as saved: the editor's HTML cut down to its own safe tags, or
    // nothing when it's blank.
    public static function cleanBio(?string $bio): ?string
    {
        return RichText::isBlank($bio) ? null : RichText::toSafeHtml($bio);
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

        if ($this->isAccountant()) {
            return in_array($permission, self::ACCOUNTANT_PERMISSIONS, true);
        }

        return ! in_array($permission, config('permissions.super_admin_only'), true)
            && in_array($permission, $this->permissions ?? [], true);
    }

    // Active staff who have a permission: super admins, and team members
    // it's been granted to (who to alert about a failed invoice send, ...).
    // Never the accountant, who isn't staff.
    public static function withPermission(string $permission)
    {
        return static::whereNull('deactivated_at')
            ->where('role', '!=', self::ROLE_ACCOUNTANT)
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

    public function isAccountant(): bool
    {
        return $this->role === self::ROLE_ACCOUNTANT;
    }

    // Active staff: the studio's own people, still here -- for every list
    // of people to pick from (assignments, Chat, a proposal's team).
    public function scopeStaff($query)
    {
        return $query->whereNull('deactivated_at')->where('role', '!=', self::ROLE_ACCOUNTANT);
    }

    // The studio's own people -- everyone but the accountant. Chat, the
    // team list and the rest of the staff app are theirs.
    public function isStaff(): bool
    {
        return ! $this->isAccountant();
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

    // The tasks this person has starred (TaskFavoriteController).
    public function favoriteTasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_favorites')->withTimestamps();
    }

    // The Chat channels and direct messages this person is in.
    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class)
            ->withPivot(['last_read_message_id', 'joined_at', 'muted', 'hidden_at'])
            ->withTimestamps();
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }
}
