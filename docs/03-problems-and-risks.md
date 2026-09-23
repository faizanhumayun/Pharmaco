# 03 — Problems & Risks in the Proposed Model

A critical review of the specification. Ordered within each section by how expensive
the problem is to fix after launch.

---

## Accounting problems

### A1 · "Total Purchase Amount" is a single field, but purchases have two cash effects — **critical**

A purchase either increases payables or decreases cash. One input field cannot
express both. If purchases are assumed to be all credit, cash is overstated by every
cash purchase ever made; if assumed all cash, cash is destroyed and payables never
grow.

**Fix:** split the input into *Credit Purchases* and *Cash Purchases*, exactly as
sales are already split. The specification already recognises this in prose but the
field list does not reflect it.

### A2 · Total Sales, Cash Sales and Credit Sales are three independent inputs — **critical**

Three fields where only two are free. An operator will enter figures that do not
add up, and the ledger will silently accept an internally inconsistent day.

**Fix:** capture *Cash Sales* and *Credit Sales*; display Total Sales as a computed,
read-only figure. Never accept all three. The same applies to purchases.

### A3 · "Purchase Amount" is not "cost added to stock" — **partly resolved**

*Resolved in part by [D3](00-decisions.md): you are not sales-tax registered, so tax
is part of cost and causes no distortion.*

What remains: a company invoice is quoted before trade discount and before bonus
goods. An invoice of 200,000 less a 5% trade discount adds 190,000 of cost to stock,
not 200,000. Debiting the gross figure inflates stock permanently, on every purchase
that carries a discount.

**Fix:** one **Purchase discount** field on the daily entry; debit Stock with the net
figure only.

### A4 · "Calculated Profit" is undefined — **critical**

Gross margin? Net of expenses? Before or after discounts? Including or excluding
sales tax? Each reading produces a different COGS from the same day's data, and
therefore a different stock value, permanently.

*Resolved by [D1](00-decisions.md): gross margin — sales minus cost of goods,
before operating expenses.*

**Fix:** label the input field with that definition rather than the word "profit",
so it is visible at the point of entry. Still verify against three real POS reports
before Phase 5 — in particular whether the POS figure accounts for sales returns,
and whether any non-stock revenue is in its sales total without a matching cost.

### A5 · Expenses are deducted from cash but the model never deducts them from profit

The specification's daily entry lists "Other Cash Expenses" under the cash
calculation only. If expenses never reach the profit figure, "daily profit" is gross
margin wearing the wrong label, and the owner will over-estimate earnings by the
full operating cost of the business.

**Fix:** report two clearly labelled figures — *Gross Profit* (sales − COGS) and
*Net Profit* (gross − expenses − stock variance − bad debts). Never show one number
called "profit".

### A6 · The accounting equation will not balance as specified

Assets 1,500,093 against liabilities 3,200,000 leaves −1,699,907 with nothing to
absorb it. Without an equity account the opening entry cannot be recorded at all.

**Fix:** `3900 Opening Balance Equity`, and treat a large balance in it as an
exception to be explained, not a plug. See [07](07-opening-balance-workflow.md).

---

## Missing transactions

Each of these will occur in the first month of real use. Without them, a balance
becomes wrong and **no amount of careful data entry will fix it**, because there is
no field to enter the truth into.

| Missing | Why it is required | Balance corrupted without it |
|---|---|---|
| **Owner drawings & capital injection** | Owners of family distribution businesses take cash from the till routinely. | Cash. Every withdrawal makes cash-in-hand wrong until someone invents a fake expense to explain it. **This is the single most common reason systems like this get abandoned.** |
| ~~Bank accounts and transfers~~ | *Not applicable — [D4](00-decisions.md): the business runs on cash.* | — |
| **Cheques received or issued** | Raised in priority by D4. If any pharmacy settles by cheque, or any company is paid by one, there is nowhere for it to go. | Cash, overstated until clearing; a bounce corrupts cash *and* receivables. See Q6. |
| **Sales returns** | Pharmacies return near-expiry and damaged goods constantly in this trade. | Receivables *and* stock *and* profit. |
| **Purchase returns / expiry claims** | Distributors claim expired stock back from companies. | Payables *and* stock. |
| **Bad debt write-off** | Some market credit never comes back. | Receivables, permanently overstated. |
| **Discount allowed on settlement** | A customer pays 48,000 to settle 50,000. | Receivables — the 2,000 has nowhere to go, so the account never clears. |
| **Discount received from company** | Early-payment discounts. | Payables, same problem inverted. |
| **Stock adjustment / physical verification** | Expiry, breakage, theft. | Stock, unboundedly. See [02](02-accounting-model.md#stock-valuation). |
| **Fixed asset purchase** | A delivery van is not an expense. | Cash is right but profit is wrong by the full purchase price in one day. |
| **Cash sales that were actually bank transfers** | Increasingly common. | Cash. |

Recommended minimum additions for v1: drawings/capital, bank + transfers, sales
returns, purchase returns, bad debt, both discount types, stock adjustment. Fixed
assets and accrued expenses can wait, provided the accounts exist so the numbers do
not land in the wrong place when they arrive.

---

## Stock problems

Covered in full in [02 — Stock valuation](02-accounting-model.md#stock-valuation).
In summary:

- The `COGS = Sales − Profit` identity is arithmetically correct and inherits every
  error in the POS's profit calculation.
- Stock is the only headline balance with **no independent verification**, so errors
  are permanent and cumulative — unlike cash, which is counted nightly.
- Shrinkage, expiry and breakage are structurally invisible to the formula and all
  push book stock *above* reality.
- **Verdict:** acceptable for v1 **only if** paired with mandatory periodic physical
  stock verification posting variance to profit and loss, and a dashboard that shows
  how stale the stock figure is.

---

## Cash problems

Cash is the balance the owner will check against a physical count, so it is the
balance that determines whether the system is trusted. Every way it can go wrong:

1. **Owner takes cash without recording it** → see missing transactions. The most
   likely cause of a mismatch, and it needs a first-class Drawings action, not an
   expense category, or it will corrupt profit as well as cash.
2. **Cheques received treated as cash** → cash overstated until they clear, and if
   one bounces, both cash and receivables are wrong. Post-dated cheques are the norm
   in this trade, and [D4](00-decisions.md) makes this the main open edge case. The
   cheapest correct answer is a single `1020 Cheques in Hand` account plus received
   and cleared/bounced actions — far less work than a bank module. See Q6.
3. **An all-cash business carries more physical risk**, which makes the nightly
   count the only independent check on the largest flow in the business.
4. **Cash held overnight by recovery officers** counted as cash in hand → the safe
   will not match. A separate float account per salesman solves it; deferring this
   is acceptable in v1 only if collections are same-day deposit.
5. **Expenses paid from the owner's pocket** → cash correct, expenses missing.
   Handled as capital injection + expense.
6. **Duplicate day submission** → every balance doubles for that day. Prevented by a
   unique constraint on `(business_id, business_date)`, not by UI discipline.
7. **Fixed asset purchases booked as expenses** → cash right, profit badly wrong.
8. **Rounding** — mitigated by `DECIMAL(18,2)` end to end and never touching a float.

**Design response — now the single most important control in the system, because
[D4](00-decisions.md) routes every rupee through it:** an evening **cash
reconciliation** step in the daily closing.
The operator counts physical cash; the system compares it to ledger cash-in-hand and
requires any difference to be explicitly recorded with a reason. This turns an
invisible, compounding error into a visible daily one, and it is the single most
valuable control in the whole application.

---

## Market receivable problems

1. **Collections cannot exceed what is owed** — but with a single total receivable
   and no customer ledgers, the only check available in v1 is that the balance does
   not go negative. Enforce it, and surface a warning rather than a hard block
   (advance payments are legitimate, but they belong in `2100 Customer Advances`,
   not as a negative receivable).
2. **Settlement discounts** leave a stub balance forever if there is no discount
   transaction. Covered above.
3. **Sales returns against credit sales** reduce receivables. Without them,
   receivables only ever grow.
4. **Bad debt** — receivable value is not recoverable value. Without a write-off
   action, the total pending figure flatters the position, sometimes by years of
   accumulated dead balances.
5. **Ageing is impossible in v1.** With one aggregate balance there is no invoice
   date to age against, so "how much of this is 90+ days old?" — the most useful
   question about market credit — cannot be answered. This is an accepted v1
   limitation, and it is a strong argument for bringing customer ledgers forward.
   Say so to the business owner explicitly rather than letting them discover it.
6. **Opening receivables have no age either**, which compounds point 5 for the first
   several months.

---

## Company payable problems

1. **Purchases must not affect payables when paid in cash** — see A1.
2. **Payments made against a specific company** cannot be tracked in v1's single
   aggregate. Record the company name as free text on the payment now, so that when
   Phase 8 arrives there is history to migrate rather than a blank.
3. **Advances to companies** produce a negative payable, which is not a payable at
   all — it is an asset. Netting them hides both. `1300 Advances to Companies`.
4. **Purchase returns and expiry claims** reduce payables only when the company
   issues the credit note, which in this trade can be months later. Recording the
   reduction at the moment goods are returned overstates your position by the
   disputed amount. In v1, only post the reduction when the credit note is received.
5. **The reconciliation requirement** — total payable ≡ sum of company balances — is
   satisfied structurally by control accounts. Any other design will drift.

---

## Database problems

1. **`DECIMAL` is correct, `FLOAT` is not** — the specification is right. But note
   that PHP arithmetic on a `DECIMAL` column still happens in floats unless it is
   cast. Use `DECIMAL(18,2)` in MySQL, cast to string in Eloquent, and do arithmetic
   with `bcmath` or a `Money` value object. Aggregate with `SUM()` in SQL where
   possible — MySQL's DECIMAL arithmetic is exact.
2. **Every composite unique constraint must include `business_id`**, or business A
   will collide with business B. This is the most common multi-tenant bug.
3. **`opening_balances` as four columns on one row is a denormalization** that
   cannot represent a fifth balance (bank, fixed assets) without a migration. Prefer
   an `opening_balances` header plus `opening_balance_lines` keyed by account — then
   adding bank at cutover is data, not schema.
4. **Do not put `current_stock`, `current_cash` etc. on `businesses`.** The
   specification correctly warns against this for opening balances; the same applies
   with more force to current balances. Cache them in a snapshot table that is
   rebuildable from the ledger, never as authoritative fields.
5. **Indexes** — the hot query is "sum entries for one business, one account, up to
   one date". A covering index on `(business_id, account_id, business_date, status)`
   including `debit, credit` matters from day one.
6. **No soft deletes on financial tables.** `deleted_at` on a ledger entry creates a
   number that is invisible but present. Use reversals.
7. **Foreign keys `ON DELETE RESTRICT`** for everything financial. A cascading
   delete on a business would silently erase its history.
8. **CHECK constraints** (MySQL 8): `debit >= 0`, `credit >= 0`, and
   `(debit = 0 OR credit = 0)` — a line is one or the other, never both.
9. **`business_date` must be `DATE`, not `DATETIME`.** Timezone drift on a
   `DATETIME` will move transactions between business days.

---

## Authorization problems

1. **App Owner is not a business role.** They operate the platform and are not a
   member of any business. Modelling them as a role inside a business forces
   awkward "which business am I?" logic everywhere. Model as a platform-level flag
   or a global role outside the business scope.
2. **Role must be per-business, not per-user.** A user may be Owner of one business
   and Operator of another. `role` as a column on `users` cannot express this — it
   belongs on the `business_user` pivot.
3. **Route model binding is the isolation hole.** `/businesses/{business}/entries/{entry}`
   will happily load an entry from another business unless bindings are scoped.
   Use `Route::scopeBindings()` *and* a global scope *and* a policy — three layers,
   because this failure is silent and severe.
4. **`business_id` must never come from the request.** Take it from the
   authenticated session context, validated against membership on every request.
5. **"Cannot edit finalized records" is an authorization rule, not a UI rule.**
   Enforce it in the policy and in the service; hiding the button is not security.
6. **The App Owner should not be able to edit finalized transactions either.** The
   specification says as much. Enforce it — a super-admin bypass in a financial
   system destroys the audit trail's credibility.

---

## Multi-business problems

1. **Global scope is necessary but not sufficient** — `Model::withoutGlobalScopes()`
   in one careless query, or a raw `DB::table()` call, bypasses it entirely. Ban raw
   queries on financial tables outside the ledger service.
2. **Aggregate/report queries are where leaks happen**, because they are written as
   raw SQL for performance. Every one needs an explicit `business_id` predicate and
   a test that proves it.
3. **Seeded chart of accounts must be per business**, and account ids must never be
   assumed to be the same across businesses. Reference by code, resolve within
   business.
4. **The App Owner's cross-business views** are the one legitimate exception and
   must be an explicit, separately authorized code path — not the absence of a
   filter.
5. **Business deletion/deactivation** must be a status change, never a delete.

---

## Audit problems

1. **The audit log must record the values, not just the event.** "User X updated
   transaction 42" is useless; "changed amount from 50,000 to 5,000, reason: typo"
   is an audit trail.
2. **Reason is mandatory on every correction.** Enforce at validation, not by
   convention.
3. **The audit log must not be writable by the application's ordinary code paths.**
   Write-only, append-only, no update or delete route exists at all.
4. **A reversal must link to its original** in both directions, and both must remain
   visible in listings — hiding the reversed original is how history quietly rewrites
   itself.
5. **Finalized ≠ immutable unless enforced.** The lifecycle is worthless if a
   service method can still call `->update()`. Guard it in the model (`saving` event
   that throws when the record is locked), not only in the controller.
6. **Who finalized it and when** must be captured at the moment of finalization,
   along with the *values as finalized*, so a later dispute can compare.

---

## Daily closing problems

1. **Gaps.** If day 3 is closed but day 2 was never closed, the "previous closing
   balance" chain is broken. Enforce sequential closing: a day may not close until
   the previous business day is closed.
2. **Late entries.** A company invoice for the 1st arrives on the 4th, after the 1st
   is closed. Three options, and one must be chosen deliberately:
   - **Reopen** the day (App Owner only, logged, reason required) and re-close it.
     Changes history but honestly, with a trail. **Recommended before month lock.**
   - **Post to the open day** with `original_business_date` recorded. Keeps history
     immutable but misstates both days.
   - **Refuse.** Clean and unusable.
3. **Backdating within an open period** must be allowed (yesterday's entry typed
   this morning is normal), but only forward of the last closed date.
4. **Corrections after close** must be adjustment or reversal transactions dated in
   the open period, never edits.
5. **Closing must be blocked**, not merely warned, while the day has unreconciled
   cash or a draft transaction — otherwise the lock preserves a known-wrong day.
6. **Next day's opening is derived, not stored as editable data.** Store the closing
   snapshot for speed and audit, but make it rebuildable, and never let anyone type
   into it. Otherwise the whole "no manually editable balances" requirement is
   defeated at the one place it matters most.
7. **Timezone.** Fix the business timezone on the business record and derive
   `business_date` from it, or a day will close at the wrong moment.

---

## Reporting problems

1. **Any report that computes a balance with its own query will eventually disagree
   with the dashboard.** One service, one derivation, every consumer. This is worth
   being dogmatic about.
2. **Snapshots must be a cache, never a source.** `php artisan snapshots:rebuild`
   must reproduce them exactly from the ledger. If a snapshot can hold a value the
   ledger cannot reproduce, it is a second source of truth and the system has the
   problem it was built to solve.
3. **The POS and this app will disagree on daily sales.** They are different systems
   with different cutoffs. Decide which is authoritative for reporting (this app
   should be, since it is what the position is built from) and provide a variance
   report rather than pretending they will match.
4. **Draft transactions must be excluded from every figure**, consistently. A single
   report that includes drafts will produce a number no one can reconcile.
5. **Trend charts read from snapshots; today's card reads live.** At the boundary
   they must agree — test it, because an off-by-one on date filtering here is easy
   and looks like a data problem rather than a bug.
