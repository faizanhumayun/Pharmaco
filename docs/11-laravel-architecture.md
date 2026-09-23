# 11 — Laravel 12 Architecture

Conventional Laravel with one deliberate addition: **domain services for anything
that touches money.** Models stay thin, controllers stay thin, and the accounting
rules live in one place where they can be tested.

## Directory structure

```
app/
├── Models/                                 thin: relations, casts, scopes only
│   ├── Business.php
│   ├── BusinessUser.php
│   ├── Account.php
│   ├── Transaction.php
│   ├── LedgerEntry.php
│   ├── OpeningBalance.php
│   ├── OpeningBalanceLine.php
│   ├── DailyEntry.php
│   ├── DailyClosing.php
│   ├── StockVerification.php
│   ├── ExpenseCategory.php
│   └── Company.php                         Phase 8, table exists earlier
│
├── Enums/
│   ├── TransactionType.php                 PURCHASE_CREDIT, SALE_CASH, COGS, …
│   ├── AccountType.php                     asset | liability | equity | income | expense
│   ├── AccountCode.php                     '1000', '1100', … referenced by constant, never by name
│   ├── DocumentStatus.php                  draft | posted | reversed
│   ├── OpeningBalanceStatus.php            draft | finalized | locked
│   ├── BusinessStatus.php                  setup | active | suspended | archived
│   └── BusinessRole.php                    owner | operator
│
├── Domain/
│   ├── Ledger/
│   │   ├── LedgerPoster.php                ★ the ONLY writer of ledger_entries
│   │   ├── BalanceService.php              ★ the ONLY reader of balances
│   │   ├── ChartOfAccounts.php             seeds a business's accounts
│   │   ├── PostingSpec.php                 value object: date, type, lines, source
│   │   └── TrialBalance.php                integrity check
│   │
│   ├── Opening/
│   │   ├── Actions/CreateOpeningBalance.php
│   │   ├── Actions/UpdateOpeningBalance.php
│   │   ├── Actions/FinalizeOpeningBalance.php
│   │   └── OpeningBalanceCalculator.php    assets, liabilities, the 3900 balancer
│   │
│   ├── Daily/
│   │   ├── Actions/SaveDailyEntry.php
│   │   ├── Actions/PostDailyEntry.php
│   │   ├── Actions/ReverseDailyEntry.php
│   │   ├── DailyEntryPostingService.php    inputs → the set of typed transactions
│   │   └── DailyEntryPreview.php           the "resulting position" panel
│   │
│   ├── Closing/
│   │   ├── Actions/FinalizeDailyClosing.php
│   │   ├── Actions/ReopenDailyClosing.php
│   │   ├── DailyClosingCalculator.php      computes every closing figure from the ledger
│   │   └── ClosingGuard.php                the precondition checklist
│   │
│   ├── Stock/
│   │   ├── Actions/RecordStockVerification.php
│   │   └── StockConfidence.php             days since verification, drift
│   │
│   ├── Corrections/
│   │   ├── Actions/ReverseTransaction.php
│   │   └── Actions/PostAdjustment.php
│   │
│   └── Reporting/
│       ├── DashboardQuery.php
│       ├── TrendQuery.php
│       ├── AlertEngine.php
│       └── AccountStatementQuery.php       ready for per-company statements
│
├── Support/
│   ├── Money.php                           value object + Eloquent cast, bcmath inside
│   ├── CurrentBusiness.php                 request-scoped business context
│   └── Concerns/BelongsToBusiness.php      trait: global scope + auto-fill business_id
│
├── Policies/
│   ├── BusinessPolicy.php   OpeningBalancePolicy.php   DailyEntryPolicy.php
│   ├── DailyClosingPolicy.php   TransactionPolicy.php   ReportPolicy.php
│
├── Http/
│   ├── Middleware/SetCurrentBusiness.php   resolves + validates business context
│   ├── Requests/                           all validation, none in controllers
│   └── Controllers/                        thin: authorize, delegate, redirect
│
├── Console/Commands/
│   ├── VerifyTrialBalance.php              nightly: Σdr = Σcr per business
│   ├── RebuildClosings.php                 --from=YYYY-MM-DD, must reproduce exactly
│   ├── CheckIntegrity.php                  stored closings vs rebuilt; the ≡ identities
│   └── RecomputeAlerts.php
│
└── Providers/
    └── AuthServiceProvider.php             Gate::before for platform admin, scoped tightly
```

## The two starred classes

Everything in this design rests on these being the only paths in and out of the
ledger. If a second class writes entries, every invariant becomes a hope.

```php
final class LedgerPoster
{
    public function post(PostingSpec $spec): Transaction
    {
        $debits  = $spec->lines->sum(fn ($l) => $l->debit);
        $credits = $spec->lines->sum(fn ($l) => $l->credit);

        throw_unless($debits->equals($credits),
            UnbalancedTransactionException::class);

        throw_unless($spec->date->gt($spec->business->locked_through_date ?? '1970-01-01'),
            ClosedPeriodException::class);

        // … create transaction + entries, both inside the caller's DB::transaction
    }
}
```

`BalanceService` exposes four methods (see
[10](10-dashboard-architecture.md#where-the-numbers-come-from--one-rule)) and every
card, report and export composes them.

## Conventions worth writing down

| Concern | Decision |
|---|---|
| **Money in PHP** | `Money` value object wrapping a string, `bcmath` for arithmetic, `DECIMAL(18,2)` in MySQL. Never a float, never `+` on a model attribute. |
| **Validation** | FormRequests only. A controller that validates is a controller doing the model's job. |
| **Authorization** | Policies, invoked with `$this->authorize()`. No `if ($user->role === 'owner')` anywhere. |
| **Atomicity** | Every financial write is wrapped in `DB::transaction`. Posting is synchronous — never queued. |
| **Events** | Fired `afterCommit`, used only for notifications and alert recomputation. Nothing a balance depends on. |
| **Jobs** | Only for genuinely deferrable work: emails, exports, nightly recomputes. |
| **Immutability** | `saving` listener on `Transaction` and `LedgerEntry` throws when the record is posted. Enforced at the model, not only the controller. |
| **Mass assignment** | Explicit `$fillable` on every financial model. Never `$guarded = []`. |
| **Global scope** | `BelongsToBusiness` trait applies `BusinessScope` and auto-fills `business_id` on create. |
| **Raw SQL** | Banned on financial tables outside `BalanceService`, which takes `business_id` as a required argument rather than an optional filter. |
| **Testing** | Pest. Invariant tests are first-class — see below. |

## Front end

**Blade + Tailwind + Alpine. No SPA, no Livewire, no Inertia.**

The v1 surfaces are forms, tables and a dashboard. Blade is the right tool and there
is no architectural reason to reach further. The only genuinely interactive
requirement — live totals and the position preview on the daily-entry form — is
about forty lines of Alpine.

Revisit this exactly once: when invoice-level line-item entry arrives, a reactive
component earns its cost, and Livewire 3 would then be the right answer for those
screens specifically. Not before.

Charts: Chart.js from a pinned CDN or bundled through Vite. Nothing more.

## Invariant tests — the ones that make the design real

These belong in CI from Phase 4, and in a nightly artisan command against production:

```
✓ every transaction balances                Σdebit = Σcredit per transaction_id
✓ every business balances                   Σdebit = Σcredit per business_id
✓ posted transactions are immutable         update() throws, per role
✓ control account = sum of children         balance(2000) = Σ balance(2000-*)
✓ two derivations of net position agree     A − L = equity + profit − drawings
✓ receivable delta identity                 movement ≡ credit sales − collections − …
✓ closings rebuild deterministically        rebuild == stored, every day
✓ no ledger entry outside a transaction     orphan check
✓ business isolation                        user of A gets 403 on every route of B
✓ closed period rejects postings            per role, including App Owner
✓ duplicate daily entry rejected            at the database level, under concurrency
```

The last one needs an actual concurrent test, not a sequential one — the unique
constraint is the guard, and the test should prove the application handles the
resulting exception gracefully rather than 500-ing.

## Packages

| Package | For |
|---|---|
| `laravel/breeze` | Auth scaffolding (Blade stack) |
| `spatie/laravel-permission` | Roles and permissions, teams feature = businesses |
| `spatie/laravel-activitylog` | Audit trail |
| `barryvdh/laravel-dompdf` | Statement and closing PDFs, when needed |
| `pestphp/pest` | Tests |
| `larastan/larastan` | Static analysis, level 6+ on `app/Domain` |

Resist anything else. In particular, resist an accounting package — the ledger here
is 200 lines and fits the domain exactly; a general-purpose one would fit it
approximately.
