# 05 — Database Design

## Conventions applied throughout

| Convention | Rule |
|---|---|
| Money | `DECIMAL(18,2)` — never `FLOAT`/`DOUBLE`. Max 9,999,999,999,999,999.99 PKR. Cast to string in Eloquent; arithmetic via `bcmath` or a `Money` value object; aggregate with SQL `SUM()`. |
| Business date | `DATE` — never `DATETIME`. Timezone drift on a datetime moves transactions between business days. |
| System time | `created_at` / `updated_at` `TIMESTAMP`, UTC, always distinct from business date. |
| Tenancy | Every business-scoped table carries `business_id BIGINT UNSIGNED NOT NULL`, indexed first in every composite index and included in **every** unique constraint. |
| Deletes | No `deleted_at` on financial tables. `ON DELETE RESTRICT` on all financial foreign keys. |
| Keys | `id BIGINT UNSIGNED AUTO_INCREMENT` primary key throughout. |
| Enums | Stored as `VARCHAR(30)` with a PHP backed enum + `CHECK` constraint, not MySQL `ENUM` (which requires a migration to extend). |

---

## Identity & tenancy

### `users`

Standard Laravel users plus a platform-level flag.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | | |
| `name` | varchar(120) | no | |
| `email` | varchar(160) | no | **unique** |
| `password` | varchar(255) | no | |
| `is_platform_admin` | boolean | no | default `false`. The App Owner. Not a business role. |
| `is_active` | boolean | no | default `true`. Deactivation, never deletion. |
| `last_login_at` | timestamp | yes | |
| `created_at`, `updated_at` | timestamp | | |

**Indexes:** unique(`email`), index(`is_platform_admin`)

**Constraint:** a user with `is_platform_admin = true` needs no business membership.
Business-scoped queries must never assume membership exists.

### `businesses`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | | |
| `name` | varchar(160) | no | |
| `slug` | varchar(160) | no | **unique** |
| `business_type` | varchar(30) | no | `distributor` \| `pharmacy`. Only `distributor` usable in v1. |
| `status` | varchar(30) | no | `setup` \| `active` \| `suspended` \| `archived`. Starts `setup`. |
| `currency` | char(3) | no | default `PKR` |
| `timezone` | varchar(64) | no | default `Asia/Karachi`. Determines what "today" means. |
| `opening_date` | date | yes | Null until the opening balance is finalized. |
| `locked_through_date` | date | yes | Last closed business day. Null before first close. |
| `address`, `phone`, `email`, `ntn` | varchar | yes | |
| `created_by` | bigint unsigned FK → users | no | |
| `created_at`, `updated_at` | timestamp | | |

**Indexes:** unique(`slug`), index(`status`), index(`business_type`)

**Constraints:**
- `status` cannot move to `active` until an opening balance exists with status `locked`.
- `locked_through_date` is written only by the daily-closing service.
- **Never** add `current_stock`, `current_cash` or any balance column here.

### `business_user`

Membership and role, per business. Role lives here, not on `users`, because one
person may be Owner of one business and Operator of another.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | | |
| `business_id` | bigint unsigned FK → businesses | no | |
| `user_id` | bigint unsigned FK → users | no | |
| `role` | varchar(30) | no | `owner` \| `operator`. Extensible. |
| `is_active` | boolean | no | default `true` |
| `created_at`, `updated_at` | timestamp | | |

**Indexes:** **unique(`business_id`, `user_id`)**, index(`user_id`, `is_active`)

### Roles & permissions

Use `spatie/laravel-permission` **with the teams feature enabled**, teams = businesses.
This gives per-business role assignment and a permission registry that can grow
without schema changes. The `business_user.role` column above remains as the
authoritative membership record; Spatie handles the permission expansion.

Tables created by the package: `roles`, `permissions`, `model_has_roles`,
`model_has_permissions`, `role_has_permissions` — all carrying a `team_id`
(= `business_id`).

---

## Ledger core

### `accounts`

Chart of accounts, seeded per business at creation with fixed codes.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | | |
| `business_id` | bigint unsigned FK → businesses | no | |
| `code` | varchar(20) | no | `1000`, `2000-001`, … |
| `name` | varchar(120) | no | |
| `type` | varchar(20) | no | `asset` \| `liability` \| `equity` \| `income` \| `expense` |
| `parent_id` | bigint unsigned FK → accounts | yes | Self-referencing. Sub-ledgers hang here. |
| `is_control` | boolean | no | default `false`. Control accounts are posted to only while they have no children. |
| `is_postable` | boolean | no | default `true`. Headers are not postable. |
| `subject_type`, `subject_id` | varchar(120), bigint unsigned | yes | Polymorphic link to a `companies` / `customers` row in later phases. |
| `is_system` | boolean | no | Seeded accounts cannot be deleted or renamed by users. |
| `created_at`, `updated_at` | timestamp | | |

**Indexes:** **unique(`business_id`, `code`)**, index(`business_id`, `type`),
index(`parent_id`), index(`subject_type`, `subject_id`)

**Constraints:**
- Posting to a non-postable or non-existent account throws.
- `parent_id` must belong to the same business — enforced in the service; MySQL
  cannot express it as a FK.

### `transactions`

The header. One per business event.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | | |
| `business_id` | bigint unsigned FK → businesses | no | |
| `business_date` | **date** | no | The day it belongs to. |
| `type` | varchar(40) | no | `PURCHASE_CREDIT`, `SALE_CASH`, … see [02](02-accounting-model.md#transaction-types-and-their-postings) |
| `reference_no` | varchar(60) | yes | Company invoice no., cheque no., receipt no. |
| `narration` | varchar(255) | yes | |
| `amount` | decimal(18,2) | no | The transaction's headline amount, for listing and search. Not used in balance maths. |
| `source_type`, `source_id` | varchar(120), bigint unsigned | yes | Polymorphic → the document that created it (`DailyEntry`, `OpeningBalance`, …). |
| `status` | varchar(20) | no | `draft` \| `posted` \| `reversed`. Only `posted` counts. |
| `reversal_of_id` | bigint unsigned FK → transactions | yes | Set on reversal transactions. |
| `reversed_by_id` | bigint unsigned FK → transactions | yes | Set on the original when reversed. Both directions, both visible. |
| `correction_reason` | varchar(500) | yes | **Required** when `type` is `ADJUSTMENT` or `REVERSAL`. |
| `original_business_date` | date | yes | Set when a late entry is posted to a later day than it belongs to. |
| `created_by` | bigint unsigned FK → users | no | |
| `posted_at` | timestamp | yes | |
| `created_at`, `updated_at` | timestamp | | Note `created_at` ≠ `business_date`, deliberately. |

**Indexes:** index(`business_id`, `business_date`, `status`),
index(`business_id`, `type`, `business_date`),
index(`source_type`, `source_id`), index(`reversal_of_id`)

**Constraints:**
- A `posted` transaction is immutable. Enforce in the model's `saving` listener,
  not only in the controller.
- `business_date` must be `>` the business's `locked_through_date` at posting time,
  unless the day has been formally reopened.
- No soft deletes.

### `ledger_entries`

The lines. **The single source of every balance in the application.**

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | | |
| `business_id` | bigint unsigned FK → businesses | no | Denormalized from the transaction, deliberately — it keeps the hot index single-table. |
| `transaction_id` | bigint unsigned FK → transactions | no | |
| `account_id` | bigint unsigned FK → accounts | no | |
| `business_date` | **date** | no | Denormalized from the transaction, for the same reason. |
| `debit` | decimal(18,2) | no | default `0.00` |
| `credit` | decimal(18,2) | no | default `0.00` |
| `memo` | varchar(255) | yes | |
| `created_at` | timestamp | no | No `updated_at` — rows are never updated. |

**Indexes:**
- **`(business_id, account_id, business_date)`** — the hot path. Consider a covering
  index including `debit, credit` once volume justifies it.
- `(business_id, business_date)` — daily totals.
- `(transaction_id)` — drill-down.

**CHECK constraints (MySQL 8):**
```sql
CHECK (debit  >= 0),
CHECK (credit >= 0),
CHECK (debit = 0 OR credit = 0)   -- a line is one or the other, never both
```

**Service-enforced invariants:**
- `Σ debit = Σ credit` per `transaction_id`. Asserted in `LedgerPoster` before commit.
- `business_id` and `business_date` always match the parent transaction.
- Rows are never updated and never deleted, by anything, ever.

---

## Opening balance

Normalized as header + lines, so adding a fifth opening figure (bank, fixed assets)
at cutover is data entry rather than a migration. This is a deliberate departure
from the four-column shape in the specification, for that reason.

### `opening_balances`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | | |
| `business_id` | bigint unsigned FK → businesses | no | |
| `opening_date` | date | no | The position is *as at the end of* this date. |
| `status` | varchar(20) | no | `draft` \| `finalized` \| `locked` |
| `total_assets` | decimal(18,2) | no | Snapshot at finalization. |
| `total_liabilities` | decimal(18,2) | no | Snapshot at finalization. |
| `net_position` | decimal(18,2) | no | Snapshot at finalization. May be negative. |
| `confirmation_text` | varchar(500) | yes | The exact wording the App Owner confirmed. |
| `notes` | text | yes | Where an unusual net position gets explained. |
| `created_by` | bigint unsigned FK → users | no | |
| `finalized_by` | bigint unsigned FK → users | yes | |
| `finalized_at` | timestamp | yes | |
| `transaction_id` | bigint unsigned FK → transactions | yes | The `OPENING` transaction produced at finalization. |
| `created_at`, `updated_at` | timestamp | | |

**Indexes:** **unique(`business_id`)** — one opening balance per business, enforced
by the database rather than by hope. index(`status`)

**Constraints:**
- Editable only while `draft`.
- `finalized` → `locked` is one-way. No route exists to move backwards.
- Finalization is what creates the `OPENING` transaction, inside one DB transaction.

### `opening_balance_lines`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | | |
| `opening_balance_id` | bigint unsigned FK | no | `ON DELETE CASCADE` — draft lines only |
| `account_id` | bigint unsigned FK → accounts | no | |
| `amount` | decimal(18,2) | no | Signed by the account's natural side |
| `note` | varchar(255) | yes | |

**Indexes:** **unique(`opening_balance_id`, `account_id`)**

---

## Daily operations

### `daily_entries`

The document: what the operator typed, stored verbatim. Its purpose is that a day
can be **re-posted** if the posting rules are later corrected.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | | |
| `business_id` | bigint unsigned FK | no | |
| `business_date` | date | no | |
| `status` | varchar(20) | no | `draft` \| `posted` |
| `purchase_credit` | decimal(18,2) | no | default 0 |
| `purchase_cash` | decimal(18,2) | no | default 0 |
| `purchase_discount` | decimal(18,2) | no | default 0 — reduces cost added to stock ([D3](00-decisions.md)) |
| `sale_cash` | decimal(18,2) | no | default 0 |
| `sale_credit` | decimal(18,2) | no | default 0 |
| `gross_profit` | decimal(18,2) | no | default 0 — as reported by the POS |
| `sales_return` | decimal(18,2) | no | default 0 |
| `purchase_return` | decimal(18,2) | no | default 0 |
| `collection_cash` | decimal(18,2) | no | default 0 |
| `discount_allowed` | decimal(18,2) | no | default 0 |
| `company_payment_cash` | decimal(18,2) | no | default 0 |
| `discount_received` | decimal(18,2) | no | default 0 |
| `expenses_cash` | decimal(18,2) | no | default 0 |
| `owner_drawing` | decimal(18,2) | no | default 0 |
| `owner_capital` | decimal(18,2) | no | default 0 |
| `derived_cogs` | decimal(18,2) | no | `(sale_cash + sale_credit − sales_return) − gross_profit`, stored for audit |
| `notes` | text | yes | |
| `created_by` | bigint unsigned FK → users | no | |
| `posted_by` | bigint unsigned FK → users | yes | |
| `posted_at` | timestamp | yes | |
| `created_at`, `updated_at` | timestamp | | |

**Indexes:** **unique(`business_id`, `business_date`)** — the duplicate-day guard.
This constraint, not UI discipline, is what prevents a day being entered twice and
doubling every balance. index(`business_id`, `status`)

**Validation rules (in the FormRequest, not the controller):**
- Every amount `>= 0`.
- `business_date > businesses.locked_through_date`.
- `business_date <= today` in the business timezone.
- Total sales and total purchases are **computed and displayed**, never entered.
- Warn (do not block) if `gross_profit` exceeds 40% or is below 0% of net sales —
  a fat-finger guard on the input that drives stock valuation.
- Warn if collections would drive market receivables negative.

### `expense_categories` and `expense_lines`

Expenses need a category to be useful in reports, but a single total is enough for
v1's cash arithmetic. Compromise: one `expenses_cash` total on the daily entry, plus
optional itemization.

`expense_categories`: `id`, `business_id`, `name`, `account_id` (FK → accounts),
`is_active`. **unique(`business_id`, `name`)**

`expense_lines`: `id`, `daily_entry_id`, `expense_category_id`, `amount`,
`description`. Sum must equal `daily_entries.expenses_cash` when any line exists.

### `daily_closings`

The closed day's figures. **This is also the snapshot table** — one table, not two,
because a closed day and a snapshot are the same thing.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | | |
| `business_id` | bigint unsigned FK | no | |
| `business_date` | date | no | |
| `status` | varchar(20) | no | `draft` \| `finalized` |
| `opening_stock`, `opening_cash`, `opening_receivable`, `opening_payable` | decimal(18,2) | no | Derived from the previous close; stored for audit |
| `purchases`, `sales`, `cogs`, `gross_profit`, `expenses`, `net_profit` | decimal(18,2) | no | The day's movements |
| `collections`, `company_payments`, `credit_sales`, `cash_sales` | decimal(18,2) | no | |
| `closing_stock`, `closing_cash`, `closing_receivable`, `closing_payable` | decimal(18,2) | no | |
| `receivable_delta`, `payable_delta` | decimal(18,2) | no | For the trend and alert engine |
| `net_position` | decimal(18,2) | no | |
| `counted_cash` | decimal(18,2) | yes | The physical count |
| `cash_variance` | decimal(18,2) | yes | `counted_cash − closing_cash` |
| `variance_reason` | varchar(500) | yes | Required when variance ≠ 0 |
| `finalized_by` | bigint unsigned FK → users | yes | |
| `finalized_at` | timestamp | yes | |
| `built_at` | timestamp | no | When the figures were last computed |
| `created_at`, `updated_at` | timestamp | | |

**Indexes:** **unique(`business_id`, `business_date`)**, index(`business_id`, `status`)

**Constraints:**
- Every column here is **derived**. Nothing in this table is user-editable, and
  `php artisan closings:rebuild` must reproduce it exactly from the ledger. If it
  cannot, this table has become a second source of truth and the core requirement
  is broken.
- Cannot finalize day `D` unless day `D−1` is finalized (or `D` is the first day
  after `opening_date`). No gaps.
- Cannot finalize while a `draft` transaction or daily entry exists for the day.

### `stock_verifications`

The control that bounds stock drift. See
[02 — Stock valuation](02-accounting-model.md#stock-valuation).

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | | |
| `business_id` | bigint unsigned FK | no | |
| `business_date` | date | no | |
| `book_value` | decimal(18,2) | no | Ledger stock at that date |
| `counted_value` | decimal(18,2) | no | Physically counted |
| `variance` | decimal(18,2) | no | `counted − book`. Posted to `5100 Stock Variance`. |
| `variance_pct` | decimal(8,4) | no | For the confidence indicator |
| `reason` | varchar(500) | yes | |
| `transaction_id` | bigint unsigned FK | yes | The `STOCK_ADJUSTMENT` produced |
| `verified_by` | bigint unsigned FK → users | no | |
| `created_at`, `updated_at` | timestamp | | |

**Indexes:** **unique(`business_id`, `business_date`)**, index(`business_id`, `business_date` desc)

---

## Future-ready (Phase 8) — create the table, defer the UI

### `companies`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | | |
| `business_id` | bigint unsigned FK | no | |
| `name` | varchar(160) | no | |
| `code` | varchar(40) | yes | |
| `account_id` | bigint unsigned FK → accounts | yes | Its child account under `2000` |
| `credit_days` | smallint unsigned | yes | |
| `contact`, `phone` | varchar | yes | |
| `is_active` | boolean | no | |

**Indexes:** **unique(`business_id`, `name`)**, unique(`business_id`, `code`)

**Migration note:** when Phase 8 lands, existing aggregate postings on `2000` are
moved to a `2000-000 Unallocated` child, and the control balance is unchanged. No
historical data is rewritten. In the meantime, record the company name as free text
in `transactions.narration` on every payment, so there is history to allocate.

---

## Audit

### `activity_log`

Use `spatie/laravel-activitylog`, extended with the columns the specification
requires. It records: subject (the model), causer (the user), event, `properties`
JSON containing `old` and `attributes`, and — added — `reason` and `business_id`.

| Added column | Type | Notes |
|---|---|---|
| `business_id` | bigint unsigned | For tenant-scoped audit views and indexing |
| `reason` | varchar(500) | **Required** for any correction event |
| `ip_address` | varchar(45) | |

**Indexes:** index(`business_id`, `created_at`), index(`subject_type`, `subject_id`),
index(`causer_id`)

**Constraints:**
- Append-only. No update or delete route exists in the application at all.
- Logged for: business creation and configuration, opening balance create/finalize/lock,
  daily entry post, day close/reopen, every reversal and adjustment, user and role
  changes, activation/deactivation, and every failed authorization attempt on a
  financial resource.

---

## Entity relationship summary

```
users ──┬── business_user ──┬── businesses ──┬── accounts ──── (self, parent_id)
        │   (role per       │                │       │
        │    business)      │                │       └── subject → companies (P8)
        │                   │                │
        └── is_platform_    │                ├── opening_balances ── opening_balance_lines ──▶ accounts
            admin (App      │                │
            Owner, no       │                ├── daily_entries ── expense_lines ──▶ expense_categories ──▶ accounts
            membership)     │                │
                            │                ├── daily_closings          (derived, rebuildable)
                            │                ├── stock_verifications
                            │                │
                            │                └── transactions ──── ledger_entries ──▶ accounts
                            │                         │  ▲                 │
                            │                         │  └── reversal_of ──┘
                            │                         └── source → DailyEntry | OpeningBalance | StockVerification
                            │
                            └── activity_log (append-only)
```

## Index strategy, stated once

The application has exactly one hot query shape:

```sql
SELECT SUM(debit) - SUM(credit) FROM ledger_entries
WHERE business_id = ? AND account_id = ? AND business_date <= ?
```

Everything else is a variation on it. Build for that:
`(business_id, account_id, business_date)` as the primary composite, and let the
`daily_closings` table absorb the historical range so the query rarely scans more
than the current open period.
