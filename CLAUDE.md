# Pharmaco — working notes

A financial control layer for pharmaceutical distribution. Laravel 12, PHP, MySQL,
Blade. The full design lives in `docs/`; this file is the short version for anyone
writing code.

## Non-negotiables

These are not style preferences. Each one exists because breaking it silently
corrupts financial data.

1. **Money never touches a float.** `DECIMAL(18,2)` in MySQL, `App\Support\Money`
   in PHP (bcmath inside), `MoneyCast` on the model. Never `+` on a money attribute.
2. **No stored balances.** Balances are derived from `ledger_entries`. If you find
   yourself adding a `current_*` or `total_*` column to hold a running figure, stop.
3. **Posted records are immutable.** Enforced in the model's `saving` listener, not
   only in the controller. Corrections are new transactions.
4. **One writer, one reader.** `LedgerPoster` is the only class that writes
   `ledger_entries`; `BalanceService` is the only class that reads balances.
   Everything else asks them. Do not add a second path — the invariants are
   guaranteed by there being exactly one.
5. **Postings are synchronous**, inside the same `DB::transaction` as the document.
   Never queued — a posting that fails silently is the worst failure mode there is.
6. **`business_id` never comes from user input.** It comes from
   `CurrentBusiness`, set by `SetCurrentBusiness` middleware from the route and
   validated against membership.
7. **`business_date` is a `DATE`**, always distinct from `created_at`, and always
   derived in the business's own timezone.
8. **No soft deletes and no delete routes on financial tables.** Archive, reverse,
   or deactivate.

## Tenancy — three layers, all of them required

A leak is silent and severe, so this is defence in depth on purpose:

- `BelongsToBusiness` trait → `BusinessScope` global scope, plus `business_id`
  stamped on create.
- `Route::scopeBindings()` on the `/b/{business}` group.
- A policy check in the controller, even when the scope already filtered.

Raw SQL is banned on financial tables outside the balance service. A global scope
does not apply to `DB::table()`, and reports are exactly where people reach for it.

## Layout

```
app/Domain/Ledger/                LedgerPoster, BalanceService, ChartOfAccounts
app/Domain/<Context>/Actions/     one invokable class per business operation
app/Domain/<Context>/<Service>    the rules, testable in isolation
app/Support/                      Money, CurrentBusiness, BelongsToBusiness
app/Enums/                        BusinessType, BusinessStatus, BusinessRole, Permission
app/Http/Requests/                all validation — never in controllers
app/Policies/                     all authorization — never `if ($user->role === …)`
```

Controllers authorize, delegate to an action, and redirect. Nothing else.

## Roles

- **App Owner** — `users.is_platform_admin`. Operates the platform, is a member of
  no business. `Gate::before` grants platform abilities *only*; it is deliberately
  not a blanket bypass.
- **Business Owner / Entry Operator** — `business_user.role`, per business, mirrored
  into Spatie roles with teams mode (`team_foreign_key = business_id`). A user may
  be owner of one business and operator of another.

Permissions live in `App\Enums\Permission`; roles are bundles of them, so a fourth
role later is configuration rather than code.

## Testing

`php artisan test` runs against MySQL (`pharmaco_test`), not SQLite — the schema
depends on MySQL behaviour and this is a financial system.

`tests/Feature/Tenancy/` is the isolation suite and `tests/Feature/Ledger/` is
the invariant suite. Every phase that adds a business-scoped table adds cases to
the first; every phase that posts adds cases to the second. Both must stay green.

Currently proven: every transaction balances, posted records are immutable for
every role including App Owner, a refused posting writes nothing at all, control
accounts equal the sum of their children, both derivations of net position agree,
receivable movement computed two ways agrees, drafts are excluded from every
figure, and MySQL CHECK constraints reject a bad line even when the service is
bypassed. `php artisan ledger:verify` runs the same checks against real data.

Helpers in `tests/Pest.php`: `platformAdmin()`, `businessWithMember($role)`.

## Front end

Blade + Tailwind + Alpine. No SPA, no Livewire, no Inertia. Revisit exactly once,
when invoice-level line-item grids arrive — not before. Charts (Phase 7) use
Chart.js.

## Posting, in practice

```php
app(LedgerPoster::class)->post(new PostingSpec(
    business:  $business,
    date:      $business->today(),      // business timezone, not the server's
    type:      TransactionType::SaleCredit,
    lines:     [
        PostingLine::debit(AccountCode::MarketReceivables, '180000.00'),
        PostingLine::credit(AccountCode::Sales, '180000.00'),
    ],
    createdBy: $user,
    narration: 'Credit sales to market',
    source:    $dailyEntry,             // the document that caused it
));
```

Accounts are named by `AccountCode`, never by id or name: ids differ per business
and names are editable. The poster refuses anything that does not balance, posts
into a closed period, names a non-postable account, or omits a reason on an
adjustment or reversal — and it writes nothing at all when it refuses.

Corrections go through `LedgerPoster::reverse($transaction, $reason, $user)`,
which mirrors every line, links both records in both directions, and leaves both
visible.
