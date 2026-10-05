<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Support\RichText;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Proposal extends Model
{
    protected $fillable = ['company_id', 'project_id', 'contact_id', 'title', 'body', 'disclaimer', 'team_user_ids', 'team_heading', 'show_about', 'estimate_amount', 'status', 'sent_at', 'accepted_at'];

    protected $casts = [
        'sent_at' => 'datetime',
        'accepted_at' => 'datetime',
        'team_user_ids' => 'array',
        'show_about' => 'boolean',
    ];

    // The heading over the team section when none is set.
    public const DEFAULT_TEAM_HEADING = 'Your team';

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

    // Who a sent proposal goes to: its own contact when one was picked,
    // otherwise the client's primary contact, otherwise any contact with an
    // email -- the same precedence invoices use for their billing contact.
    public function recipientContact(): ?Contact
    {
        $contacts = $this->company->contacts;

        return $this->contact
            ?? $contacts->firstWhere('is_primary', true)
            ?? $contacts->first(fn (Contact $contact) => filled($contact->email));
    }

    // The people in the proposal's team section, in the order chosen.
    public function teamMembers(): Collection
    {
        $ids = $this->team_user_ids ?? [];
        if ($ids === []) {
            return collect();
        }
        $users = User::whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn ($id) => $users->get($id))->filter()->values();
    }

    // The team section as shown: each person's name, position, bio (safe
    // HTML) and bio photo (not their avatar). On the client's public page
    // the photo comes through the proposal's own link (`publicToken`).
    public function teamSection(?string $publicToken = null): ?array
    {
        $members = $this->teamMembers();
        if ($members->isEmpty()) {
            return null;
        }

        return [
            'heading' => $this->team_heading ?: self::DEFAULT_TEAM_HEADING,
            'members' => $members->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'job_title' => $user->job_title,
                'bio' => RichText::toSafeHtml($user->bio),
                'photo_url' => ! $user->bio_photo_path ? null : ($publicToken
                    ? route('proposals.public.team-photo', ['token' => $publicToken, 'user' => $user->id])
                    : $user->bio_photo_url),
            ])->all(),
        ];
    }

    // The studio's About section closing the proposal (Settings), unless
    // this proposal leaves it out or there's no text: heading (the studio's
    // name when none is set) and safe HTML.
    public function aboutSection(): ?array
    {
        $studio = StudioProfile::current();
        if (! $this->show_about || RichText::isBlank($studio->proposal_about)) {
            return null;
        }

        return [
            'heading' => $studio->proposal_about_heading ?: StudioProfile::brandName(),
            'body' => RichText::toSafeHtml($studio->proposal_about),
        ];
    }

    // The proposal's date: when it was sent, or when it was written if it
    // hasn't been yet.
    public function documentDate(): Carbon
    {
        return ($this->sent_at ?? $this->created_at)->copy();
    }

    // How long its pricing holds (config/proposals.php), from its date.
    public function validUntil(): Carbon
    {
        return $this->documentDate()->addDays(config('proposals.valid_days'));
    }

    // The date row under From | Client: the date, and either how long it's
    // good for or, once accepted, when it was.
    public function documentDates(): array
    {
        return [
            'date' => $this->documentDate()->toDateString(),
            'valid_until' => $this->validUntil()->toDateString(),
            'valid_days' => config('proposals.valid_days'),
            'accepted_on' => $this->accepted_at?->toDateString(),
        ];
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
