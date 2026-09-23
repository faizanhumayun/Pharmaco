<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\AccountCode;
use App\Enums\AccountType;
use App\Support\Concerns\BelongsToBusiness;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Account extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'code', 'name', 'type', 'parent_id',
        'is_control', 'is_postable', 'is_system', 'sort_order', 'description',
        'subject_type', 'subject_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'is_control' => 'boolean',
            'is_postable' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** The company or customer this sub-ledger account represents, in later phases. */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function scopeOfType(Builder $query, AccountType $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopePostable(Builder $query): Builder
    {
        return $query->where('is_postable', true);
    }

    public function scopeCode(Builder $query, AccountCode|string $code): Builder
    {
        return $query->where('code', $code instanceof AccountCode ? $code->value : $code);
    }

    public function accountCode(): ?AccountCode
    {
        return AccountCode::tryFrom($this->code);
    }

    /**
     * Whether a posting may name this account.
     *
     * A control account is postable only while it has no children. Once Phase 9
     * gives Company Payables per-company children, the parent's balance must be
     * the sum of theirs — a direct posting to the parent would break that by
     * construction, so it is refused.
     */
    public function isPostable(): bool
    {
        if (! $this->is_postable) {
            return false;
        }

        return ! $this->children()->exists();
    }

    public function notPostableReason(): string
    {
        return $this->children()->exists()
            ? 'it has sub-accounts, so postings belong on those instead'
            : 'the account is not in use';
    }

    /** The balance in the account's natural direction, ready to display. */
    public function balance(?string $asAt = null): Money
    {
        $query = $this->entries();

        if ($asAt !== null) {
            $query->where('business_date', '<=', $asAt);
        }

        $row = $query
            ->join('transactions', 'transactions.id', '=', 'ledger_entries.transaction_id')
            ->where('transactions.status', '!=', 'draft')
            ->selectRaw('COALESCE(SUM(ledger_entries.debit), 0) as d, COALESCE(SUM(ledger_entries.credit), 0) as c')
            ->first();

        return Money::of($row->d)->minus($row->c)->times($this->type->presentationSign());
    }
}
