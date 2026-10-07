<?php

namespace App\Models;

use App\Exceptions\LedgerException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// A ledger account in the chart of accounts (Checking, Capital One Card,
// Hosting Cost...). Not the user's login "account". Never deleted --
// deactivate it instead, so its history keeps its name.
class Account extends Model
{
    public const ASSET = 'asset';

    public const LIABILITY = 'liability';

    public const EQUITY = 'equity';

    public const INCOME = 'income';

    public const EXPENSE = 'expense';

    public const TYPES = [self::ASSET, self::LIABILITY, self::EQUITY, self::INCOME, self::EXPENSE];

    protected $fillable = ['code', 'code_is_placeholder', 'name', 'type', 'system_key', 'parent_id', 'description', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'code_is_placeholder' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (Account $account) {
            if (! in_array($account->type, self::TYPES, true)) {
                throw new LedgerException("\"{$account->type}\" isn't an account type.");
            }
        });

        static::deleting(function (Account $account) {
            throw new LedgerException("Accounts are never deleted. Deactivate \"{$account->name}\" instead.");
        });
    }

    // The account the code knows by its handle ('checking',
    // 'capital_one_card'...), however it's been renamed or renumbered.
    public static function forKey(string $systemKey): self
    {
        return static::where('system_key', $systemKey)->firstOrFail();
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    // Assets and expenses grow with debits; liabilities, equity and income
    // with credits. Decides which way a balance is shown as positive.
    public function isDebitNormal(): bool
    {
        return in_array($this->type, [self::ASSET, self::EXPENSE], true);
    }

    // Debits less credits on the account, in cents, over everything posted.
    // Positive is a debit balance (cash on hand, an expense); a liability
    // or income account normally runs negative here.
    public function netDebitCents(): int
    {
        return (int) $this->lines()->sum('debit_cents') - (int) $this->lines()->sum('credit_cents');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }
}
