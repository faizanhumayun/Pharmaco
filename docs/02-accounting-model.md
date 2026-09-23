# 02 — Accounting Model

## The core principle

```
  OPENING POSITION  +  Σ(POSTED TRANSACTIONS)  =  CURRENT POSITION
```

There is no third source of truth. No table holds an editable balance. Every figure
on every screen is an aggregation over `ledger_entries`, filtered by business,
account and date.

## Why double-entry, stated plainly

The specification contains a requirement that quietly forces this decision:

> The system must always reconcile: TOTAL COMPANY PAYABLE ≡ SUM OF COMPANY LEDGER BALANCES

Any design where the total and the parts are maintained separately will eventually
disagree, and no one will be able to tell which is wrong. Under double-entry the
question cannot arise: the total *is* the sum of the parts, structurally, because
the control account balance is the sum of its children's postings.

Double-entry also gives, free of charge:

- A **self-check**: `Σ debits = Σ credits` across the whole business, always. If it
  ever fails, something is broken and you know it immediately rather than in six
  months.
- A **second derivation of net position**: assets − liabilities must equal
  equity + retained profit − drawings. Two independent paths to the same number is
  a continuous, zero-cost integrity test.
- **Future expansion without a rewrite**: per-company ledgers, per-customer
  ledgers, bank accounts, fixed assets, and loans are all just new accounts. The
  financial engine never changes.

The cost is two tables and one service class. This is cheap for what it buys, and
it is the single hardest thing to retrofit later.

**Important:** the user never sees a journal. They enter a purchase, a sale, a
collection. Posting is an implementation detail behind an ordinary form.

## Chart of accounts (v1)

Seeded per business at creation, with fixed codes. The posting service references
accounts by code constant, never by name or id.

| Code | Account | Type | Notes |
|---|---|---|---|
| `1000` | Cash in Hand | Asset | Physical cash you can count |
| `1010` | Bank | Asset | Seeded but **not postable in v1** — the business runs on cash ([D4](00-decisions.md)). Costs nothing to enable later. |
| `1100` | Market Receivables | Asset · control | Parent of per-customer accounts in a later phase |
| `1200` | Stock / Inventory | Asset | Value only, no quantities in v1 |
| `1300` | Advances to Companies | Asset | Prepayments; a negative payable is not a payable |
| `1400` | Fixed Assets | Asset | Vehicles, equipment. Keeps asset purchases out of profit |
| `2000` | Company Payables | Liability · control | Parent of per-company accounts in Phase 8 |
| `2100` | Customer Advances | Liability | Overpayments received; must not net off receivables |
| `2200` | Accrued Expenses | Liability | Optional in v1 |
| `3000` | Owner Capital | Equity | |
| `3100` | Owner Drawings | Equity · contra | **Essential.** See [03](03-problems-and-risks.md#missing-transactions) |
| `3900` | Opening Balance Equity | Equity | The balancing figure at cutover — see [07](07-opening-balance-workflow.md) |
| `4000` | Sales | Income | |
| `4100` | Sales Returns | Income · contra | |
| `4200` | Discount Received | Income | Settlement discounts from companies |
| `5000` | Cost of Goods Sold | Expense | |
| `5100` | Stock Variance | Expense | Damage, expiry, theft, count differences |
| `5200` | Discount Allowed | Expense | Settlement discounts given to the market |
| `5300` | Bad Debts | Expense | Written-off receivables |
| `6000` | Operating Expenses | Expense | Parent; children per category |

Account types drive sign convention: assets and expenses increase on debit;
liabilities, equity and income increase on credit.

## Transaction types and their postings

Every transaction the system can create, and exactly what it does. This table is
the specification for `LedgerPoster` and should be turned into a test suite
one-to-one.

| Type | Debit | Credit | Origin |
|---|---|---|---|
| `OPENING` | Stock, Cash, Bank, Receivables, Fixed Assets | Payables, Opening Balance Equity | Opening balance finalization |
| `PURCHASE_CREDIT` | Stock `1200` | Company Payables `2000` | Daily entry |
| `PURCHASE_CASH` | Stock `1200` | Cash `1000` | Daily entry |
| `SALE_CASH` | Cash `1000` | Sales `4000` | Daily entry |
| `SALE_CREDIT` | Market Receivables `1100` | Sales `4000` | Daily entry |
| `COGS` | Cost of Goods Sold `5000` | Stock `1200` | Derived — see below |
| `COLLECTION` | Cash `1000` | Market Receivables `1100` | Daily entry |
| `COMPANY_PAYMENT` | Company Payables `2000` | Cash `1000` | Daily entry |
| `EXPENSE` | Operating Expenses `6000` | Cash `1000` | Daily entry |
| `SALES_RETURN` | Sales Returns `4100` + Stock `1200` | Receivables `1100` or Cash + COGS `5000` | Daily entry |
| `PURCHASE_RETURN` | Company Payables `2000` | Stock `1200` | Daily entry |
| `DISCOUNT_ALLOWED` | Discount Allowed `5200` | Market Receivables `1100` | Collection screen |
| `DISCOUNT_RECEIVED` | Company Payables `2000` | Discount Received `4200` | Payment screen |
| `BAD_DEBT` | Bad Debts `5300` | Market Receivables `1100` | Explicit action, Owner only |
| `STOCK_ADJUSTMENT` | Stock Variance `5100` ↔ Stock `1200` | (either direction) | Stock verification |
| `OWNER_CAPITAL` | Cash `1000` | Owner Capital `3000` | Owner only |
| `OWNER_DRAWING` | Owner Drawings `3100` | Cash `1000` | Owner only |
| `ASSET_PURCHASE` | Fixed Assets `1400` | Cash `1000` / Payables | Owner only |
| `ADJUSTMENT` | any | any | Correction, requires reason |
| `REVERSAL` | mirror of the original | mirror of the original | Correction, links to original |

Types marked *Daily entry* are produced by the daily-entry form. **The daily entry
is a data-capture surface, not a transaction type.** One submitted day produces
several typed transactions. When Phase 8+ replaces the form with invoice-level
entry, it produces the *same* transaction types with finer granularity, and nothing
downstream changes. This is what makes the engine future-proof.

## Balance derivation

For any account `A` in business `B` as at date `D`:

```sql
SELECT COALESCE(SUM(debit) - SUM(credit), 0)
FROM   ledger_entries
WHERE  business_id = :B
  AND  account_id  = :A
  AND  business_date <= :D
  AND  status = 'posted';
```

Sign is flipped for liability, equity and income accounts at presentation time.
The headline figures are then:

```
stock_value        = balance(1200)
cash_in_hand       = balance(1000)
market_receivables = balance(1100) - balance(2100)     ← advances shown separately, never netted silently
company_payables   = balance(2000) - balance(1300)     ← advances to companies likewise

sales(D)           = credits to 4000 on D  -  debits to 4100 on D
purchases(D)       = debits to 1200 on D from PURCHASE_* transactions
cogs(D)            = debits to 5000 on D
gross_profit(D)    = sales(D) - cogs(D)
expenses(D)        = debits to 6000 on D
net_profit(D)      = gross_profit(D) - expenses(D) - stock_variance(D) - bad_debts(D)

collections(D)     = credits to 1100 on D from COLLECTION transactions
payments(D)        = debits to 2000 on D from COMPANY_PAYMENT transactions

receivable_delta(D) = credit_sales(D) - collections(D) - sales_returns_on_credit(D)
                      - discounts_allowed(D) - bad_debts(D)
                    ≡ balance(1100, D) - balance(1100, D-1)      ← must agree
payable_delta(D)    = credit_purchases(D) - payments(D) - purchase_returns(D)
                      - discounts_received(D)
                    ≡ balance(2000, D) - balance(2000, D-1)      ← must agree
```

The two `≡` lines are free integrity tests. Compute both ways, assert equality in
the nightly check, and surface a red flag on the dashboard if they diverge.

## Stock valuation

The specification proposes:

```
COGS = Total Sales − Calculated Profit
Closing Stock = Opening Stock + Purchases at Cost − COGS
```

**The arithmetic is correct. The reliability is not.** Evaluate it honestly:

### What is right about it

Given a correct sales figure and a correct gross profit figure, `Sales − Profit`
*is* cost of goods sold by definition. There is nothing wrong with the identity.

### Why it degrades in practice

The problem is not the formula, it is that stock is a **running balance with no
independent check**. Cash gets counted every evening. Receivables get confirmed
against customer statements. Stock, under this model, is never verified against
anything — so every error, however small, is permanent and cumulative.

Six specific error sources, all of which push in the same direction over time:

1. **"Profit" is undefined.** The POS's profit figure may be gross margin, may be
   net of discounts, may or may not exclude sales tax, may or may not include
   returns. Each interpretation gives a different COGS on the same day. This must
   be pinned down exactly — see [Q1](13-open-questions.md).
2. **"Purchases at cost" ≠ invoice total.** You are not sales-tax registered
   ([D3](00-decisions.md)), so tax is simply part of cost and causes no distortion.
   Trade discounts still do: an invoice of 200,000 less 5% adds 190,000 of cost to
   stock, not 200,000. Freight and bonus goods have the same effect. Only the net
   landed cost should debit Stock.
3. **Shrinkage is invisible.** Expiry, breakage and theft never appear in
   `Sales − Profit`, so book stock drifts steadily *above* real stock. In pharma,
   expiry alone is material.
4. **Sales returns and purchase returns** distort both sides if not captured
   separately.
5. **Rounding compounds.** A 200 PKR daily discrepancy is 73,000 PKR of phantom
   stock in a year.
6. **Non-stock revenue** (service charges, scrap) carries no COGS and skews the
   ratio if lumped into total sales.

Because stock feeds profit and profit feeds net position, an error in stock
silently corrupts two of the seven headline numbers.

### Recommendation

Keep the derived model for v1 — item-level costing is a much larger build and is
correctly deferred — but **bound the error** with three additions:

1. **Capture the purchase discount.** Since tax is not a factor, the only
   remaining gap between an invoice total and the cost added to stock is trade
   discount (and freight, if you pay it). One extra field on the daily entry closes
   it. Only net cost debits Stock.
2. **Mandatory periodic stock verification.** A `stock_verifications` record: the
   owner enters a physically counted stock value at a chosen date; the system posts
   the difference to `5100 Stock Variance` as a real profit-and-loss item. Monthly
   is right; quarterly is the minimum tolerable. This converts an unbounded drift
   into a bounded, visible, explained one.
3. **Show confidence, not just value.** The stock card on the dashboard displays
   *"last verified 14 days ago · drift at last count −1.4%"*. A number with a known
   error bar is useful; a number with an unknown error bar is dangerous.

With those three in place, the derived model is defensible for v1. Without them, the
stock figure — and therefore net position — becomes fiction within a year, and the
business will stop trusting the whole application because of it.

### The upgrade path

When item-level inventory arrives, `COGS` transactions start being produced by an
inventory service from actual batch costs instead of from `Sales − Profit`. The
transaction type, the accounts, the dashboard queries and the reports are all
unchanged. Only the producer changes.

## Sub-ledgers

`1100 Market Receivables` and `2000 Company Payables` are declared as **control
accounts**. In v1 they are posted to directly. In Phase 8, each pharmaceutical
company gets a child account (`2000-001`, `2000-002`, …) linked polymorphically to
a `companies` row, and postings move to the child. The control balance is then
`SUM(children)` by construction, and a company statement is:

```sql
SELECT business_date, narration, debit, credit,
       SUM(debit - credit) OVER (ORDER BY business_date, id) AS running_balance
FROM   ledger_entries
WHERE  account_id = :company_account
ORDER  BY business_date, id;
```

No engine change. That is the whole point of doing it this way now.

## The management position

```
  Assets       = Stock (estimate) + Cash + Market Receivables + Advances + Fixed Assets
  Liabilities  = Company Payables + Customer Advances + Accrued Expenses
  Net position = Assets − Liabilities
               ≡ Owner Capital + Opening Balance Equity + Retained Profit − Drawings
```

Two independent derivations that must agree. **Label it "Management Position", not
"Balance Sheet"** — it excludes tax positions, depreciation, loans not captured, and
any asset the owner did not declare at cutover.

Applying the specification's own opening figures:

```
  Assets:      Stock 1,000,000 + Cash 93 + Receivables 500,000  =  1,500,093
  Liabilities: Payables                                          =  3,200,000
  Net position                                                   = −1,699,907
```

A negative position of that size is a finding, not a rounding issue. It is either
true — in which case the business is trading on supplier credit well beyond its
asset base and the owner needs to know today — or the opening position is
incomplete. See [07](07-opening-balance-workflow.md#the-balancing-figure) for how
the system should handle it rather than quietly absorbing it.

---

## Implementation notes from Phase 3

Two decisions that were not obvious until the ledger was built and tested.

### Balances roll up through sub-accounts

`BalanceService::asAt()` returns an account's balance **including every account
beneath it**. Asking for Company Payables returns the total across all companies,
because that is what the question means.

This was found by the test asserting the control-account identity. Without the
roll-up, the headline payable figure would silently read **zero** the moment
Phase 9 moves postings onto per-company children — the parent would have no
direct entries of its own, and the dashboard would report no debt at all. It is a
good example of why the control-account test was worth writing before the feature
it protects exists.

`directBalance()` remains available for the account's own postings, and is what
makes the reconciliation check meaningful: rolled-up equals sum-of-children
exactly when the parent carries no stray direct postings.

### A reversal lands on the first *open* day, not simply "today"

Doc [09](09-daily-closing-workflow.md) says a correction to a closed day moves to
the current open period. "Today" is the wrong way to express that: if the lock
extends to or past today, today is itself closed, and the correction would be
refused by the very guard that redirected it.

The rule is: reverse on the original's own day while that day is open; otherwise
on the later of today and the day after the lock. The original date is recorded on
the reversal either way, so the statement can show *"02 Sep 2026 — for 28 Aug
2026"* rather than losing the fact that the correction belongs elsewhere.
