<?php

namespace App\Models;

use App\Support\Concerns\BelongsToBusiness;
use App\Support\Concerns\RecordsDevice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One company's rack, counted in one go and read as one report.
 *
 * Its counts are ordinary stock counts; this gathers them. Nothing here moves
 * stock — approving the audit approves its counts, one by one, through the
 * same action the stock audit uses.
 */
class CompanyAudit extends Model
{
    use BelongsToBusiness;
    use RecordsDevice;

    public const COUNTING = 'counting';
    public const SUBMITTED = 'submitted';
    public const APPROVED = 'approved';
    public const CLOSED = 'closed';   // decided: records kept as they were

    /** How often each company's rack should come round. A reminder, never a block. */
    public const EVERY_DAYS = 30;

    protected $fillable = [
        'business_id', 'company_id', 'business_date', 'status', 'by_batch', 'include_empty',
        'period_from', 'started_by', 'device_id', 'submitted_at', 'submitted_by',
        'decided_at', 'decided_by', 'note', 'transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'period_from' => 'date',
            'by_batch' => 'boolean',
            'include_empty' => 'boolean',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CompanyAuditItem::class);
    }

    /** The same, by the name scoped route binding looks for. */
    public function companyAuditItems(): HasMany
    {
        return $this->items();
    }

    /** The stock adjustment that booked an approved audit, if it changed anything. */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isCounting(): bool
    {
        return $this->status === self::COUNTING;
    }

    public function isDecided(): bool
    {
        return in_array($this->status, [self::APPROVED, self::CLOSED], true);
    }

    /** Audits that count towards the monthly round: finished counting. */
    public function scopeDone(Builder $query): Builder
    {
        return $query->whereIn('status', [self::SUBMITTED, self::APPROVED, self::CLOSED]);
    }

    public function statusLabel(): string
    {
        return [
            self::COUNTING => 'Counting',
            self::SUBMITTED => 'Waiting for approval',
            self::APPROVED => 'Approved',
            self::CLOSED => 'Closed · records kept',
        ][$this->status] ?? $this->status;
    }

    public function statusClasses(): string
    {
        return match ($this->status) {
            self::COUNTING, self::SUBMITTED => 'bg-amber-50 text-amber-800 ring-amber-600/20',
            self::APPROVED => 'bg-emerald-50 text-emerald-800 ring-emerald-600/20',
            default => 'bg-gray-100 text-gray-700 ring-gray-500/20',
        };
    }
}
