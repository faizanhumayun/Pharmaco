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

## Security code gate

An optional code asked for in front of the whole site, before sign-in matters:
`CheckSecurityCode` on the `web` group, `config/security-code.php` for the
default and the exemptions, codes in `security_codes`. The App Owner switches it
on and keeps the codes under Security code in the back office; that switch
(`SystemSetting` `security_code_enabled`) overrides `SECURITY_CODE_ENABLED`.
`/admin/*` and the sign-in pages are exempt on purpose, so the gate can always
be switched off. It replaces no authentication, tenancy or policy check.

## Devices

Which computer something was entered on. A browser that signs in gets a
long-lived cookie (`IdentifyDevice`) and a row in `devices`, named by the
people who use it ("Laptop POS 1") from the chip in the workspace and till
headers. `CurrentDevice` holds it for the request.

- A model that should say where it was entered uses the `RecordsDevice` trait
  and has a nullable `device_id` (bills, transactions, stock movements,
  orders, daily entries have it).
- Every audit step records `device_id`, `device_name` (as it was then) and
  `session_key`, a short fingerprint of the sign-in — never the session id.
- Work no browser did (the scheduler, an import) carries no device.
- One person in a business is signed in on one device at a time
  (`OneDeviceAtATime`, `users.signin_device`): the newest sign-in wins and
  the other device is signed out on its next request. The App Owner is exempt.

## Running in the shop, with a copy online

A shop can run the whole application on its own computer so the counter never
needs the internet. That computer holds the real books. The platform keeps a
**read-only copy** of them, which the owner opens from anywhere.

- Shop side: `mirror:push` (scheduled every five minutes) writes a snapshot of
  every table that holds the books and sends it to the platform. Offline, the
  snapshot stays on disk as a backup; unchanged books are not sent twice.
  `mirror:restore <file>` puts a snapshot back.
- Platform side: `POST /mirror/receive` (token, no session) loads a snapshot.
  By default into the platform's **own database** (`MergedImporter`): each
  shop has a block of ids (`shop id × 100,000,000`), every id and reference is
  moved into it by adding that number, people are matched by email, and the
  copy is checked against the snapshot (row counts, ledger totals) before it
  is kept. Its businesses carry `mirror_id`, which is what makes them
  read-only. Optionally into a database of the shop's own (`SnapshotImporter`),
  served by `MirrorHost` at `/copy/<slug>`.
- `MirrorReadOnly` turns away every request that would change a copy, and
  `LedgerPoster` refuses to post to one.
- **A new column that references another row must have a foreign key** (or be
  a `*_type`/`*_id` pair). `MergedImporter` stops with the column's name when
  it meets a reference it cannot classify, rather than copy a wrong number.

- **Paused for a fix.** `shop_mirrors.entry` says where a shop's books are
  entered: `shop` → `pausing` → `office` → `returning` → `shop`. The back
  office flips it (Businesses, the switch); the shop's computer asks once a
  minute (`mirror:state`) and does what it is told: lock and send its last
  entries (`ShopPause`, `ShopPaused`), or take the changes back
  (`ReturnPackage` on the platform, `ReturnApplier` in the shop) and open.
  Only in `office` is the copy writable (`Business::isReadOnlyCopy()`), and
  records made then are numbered from the shop's own block (`BlockIds`) so
  going home is the same subtraction for every row.

A copy is writable only in `office`, when the shop's computer is locked:
one place at a time, always. Never sync in both directions at once — two places
posting to one ledger is how books stop agreeing. `Snapshot`,
`SnapshotImporter`, `MergedImporter`, `ReturnPackage` and `ReturnApplier` copy rows without their models on purpose — they are the
third documented exception to the raw-SQL rule, with the balance service and
`DeleteBusiness`. Setting a shop up: `docs/shop-computer.md`.

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

### One place for the look

Every colour, font, corner and gap is defined once, in
`resources/css/pharmaco/tokens.css`. Change it there and every page follows.

- `tailwind.config.js` holds no values: `gray`/`slate`, `emerald`, `red` and the
  brand names (`paper`, `ink`, `line`…) all read CSS variables from that file.
- Never write a hex colour, an `rgb(...)` literal or a font name in a view, a
  stylesheet or the Tailwind config. Use a utility, or `var(--token)`.
- Charts take colours from `themeColor('chart-1')` (see
  `partials/theme-script.blade.php`), which reads the same file.
- The usage rules are in `docs/brand-book.md`. The landing page's words are in
  `config/landing.php`; anything not built yet is labelled "Coming soon".

### Smaller screens

Handled once, in `resources/css/app.css` ("SMALLER SCREENS"), for a 13-inch
laptop or a Windows laptop at 125–150% scaling (about 1280 × 650):

- the root font size scales with the window's width (14px at 1280, 16px by
  about 1780), and since sizes are in rem everything follows;
- on a short window (`max-height: 820px`) page headings, page padding and the
  till's rows and foot are tightened.

So write sizes in rem (Tailwind's own utilities, or `[9rem]`), not px, and a
new screen is compact for free. A row of fixed columns should give its text
column a minimum (`minmax(9rem,1fr)`) and scroll sideways rather than crush it.

### Lists: one search-and-filter bar

Every list filters through the `x-filter-bar` component
(`resources/views/components/filter-bar.blade.php`): fields with a label above
each, the apply button, a Clear button that shows only while something is
filtered, and the count on its own line under the bar. Do not hand-write a
`<form method="GET">` for filtering.

### Prices: pharmacy and distributor differ

`Business::sellsAtMrp()` — a pharmacy sells at the MRP and has no selling price
of its own (a discount is given at the till); a distributor keeps a trade price
as well as the MRP. Ask `CompanyProduct::sellingPriceFor($business)` for what a
product sells at; never read `trade_price` directly to price a sale.

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
