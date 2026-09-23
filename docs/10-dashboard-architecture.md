# 10 — Dashboard Architecture

## The question the page answers

> *Where does my business stand today, and what changed?*

Three bands, in the order an owner actually reads them: **where I stand**, **what
happened today**, **what needs attention**. Every number names its source, and every
card links to the transactions behind it. A figure you cannot drill into is a figure
nobody trusts.

## Band 1 — Position

| Card | Source | Confidence |
|---|---|---|
| **Stock Value** | `balance(1200)` | **Estimate** — must display "last verified N days ago" |
| **Cash in Hand** | `balance(1000)` | Exact — reconciled nightly |
| **Market Receivables** | `balance(1100)`, with `2100` advances shown beside it, never netted silently | Exact |
| **Company Payables** | `balance(2000)`, with `1300` advances shown beside it | Exact |
| **Net Position** | Assets − Liabilities | Inherits stock's uncertainty |

The stock card is visually distinct — a lighter treatment and an "estimated" tag.
This is not a cosmetic decision. Presenting an estimate and a counted figure with
identical confidence is how a dashboard misleads people who are doing everything
right.

## The position panel

```
  ASSETS                                    LIABILITIES
    Stock (estimated)      859,000            Company payables    3,179,000
    Cash in hand            15,093            Customer advances           0
    Market receivables     630,000            ──────────────────────────────
    Advances to companies        0            Total liabilities   3,179,000
    Fixed assets                 0
    ──────────────────────────────
    Total assets         1,504,093

  ══════════════════════════════════════════════════════════════════════════
    NET POSITION                                        −1,674,907
  ══════════════════════════════════════════════════════════════════════════
    Management position — not a statutory balance sheet.
    Excludes tax positions, depreciation, and any asset not declared at cutover.
    ⚠ Opening Balance Equity of −1,699,907 remains unexplained.  [ Explain ]
```

Two labelling requirements:

1. **"Management Position", never "Balance Sheet."** It excludes depreciation, tax
   positions, loans not captured, and anything the owner did not declare at cutover.
   Calling it a balance sheet invites decisions it cannot support.
2. **The unexplained opening equity stays visible** until it is explained. See
   [07](07-opening-balance-workflow.md#the-balancing-figure).

The second derivation is computed silently and compared:

```
  Assets − Liabilities  ≟  Opening Equity + Capital + Retained Profit − Drawings
```

If they disagree, a red integrity banner appears above everything. This costs one
query and catches an entire class of bug.

## Band 2 — Today's activity

| Card | Source |
|---|---|
| Purchases | debits to `1200` from `PURCHASE_*` on date |
| Sales | credits to `4000` − debits to `4100` on date |
| Gross Profit | sales − debits to `5000` |
| Net Profit | gross profit − `6000` − `5100` − `5300` |
| Cash Sales / Credit Sales | by transaction type |
| Market Collections | credits to `1100` from `COLLECTION` |
| Company Payments | debits to `2000` from `COMPANY_PAYMENT` |
| Expenses | debits to `6000` |
| **Change in market credit** | `balance(1100, D) − balance(1100, D−1)` |
| **Change in company payable** | `balance(2000, D) − balance(2000, D−1)` |

Those last two deserve equal prominence with profit. A distributor can be profitable
every single day while going broke, because the profit is sitting in a pharmacy's
ledger rather than in the safe. Daily profit alone will not show that; daily change
in market credit will.

## Band 3 — Attention

- **Integrity strip** — trial balance balanced ✓ · last day closed ✓ · closings
  rebuild cleanly ✓ · stock verified 28 days ago ⚠. If any of these is red, treat
  every other number on the page as suspect, and say so.
- **Unclosed days** — a count with a direct link.
- **Stock confidence** — days since verification and drift at last count.
- **Alerts** — see below.

## Trends

Read from `daily_closings`, which is why that table stores the derived figures
rather than recomputing 400 days of ledger on every page load.

Six series, 30/90/365-day ranges: sales, purchases, net profit, market receivables,
company payables, cash position. Rendered with Chart.js — no framework needed.

**The chart that matters most** is receivables and sales on the same axes. Receivables
rising while sales stay flat is the earliest possible warning of a collections
problem, and it is invisible in any single day's numbers.

## Alerts

All calculated from `daily_closings`, never entered by hand. Each has a threshold
stored per business so it can be tuned rather than argued with.

| Alert | Rule | Default |
|---|---|---|
| Market credit rising fast | 7-day receivable growth vs 7-day sales | growth > 25% of sales |
| Company payable rising fast | 30-day payable growth | > 15% |
| Cash unusually low | Cash vs 30-day average daily expense | < 3 days of cover |
| Collections lagging | 7-day collections ÷ 7-day credit sales | < 70% |
| Large receivable balance | Receivables ÷ 30-day average daily sales | > 45 days of sales |
| Unusual daily sales | Deviation from 30-day mean | > 3σ |
| Unusual daily margin | Gross margin vs 30-day mean | > 5 percentage points |
| Stock unverified | Days since last verification | > 45 days |
| Day not closed | Open days older than yesterday | any |
| Cash variance recurring | Non-zero variances in last 7 closings | ≥ 3 |

The last three are process alerts rather than financial ones, and they are the ones
that keep the financial alerts meaningful.

## Where the numbers come from — one rule

**Every figure on every screen goes through `BalanceService`.** No controller, no
Blade view, and no report writes its own aggregate query. The moment two code paths
compute "market receivables", they will eventually disagree, and the resulting
support conversation is unwinnable.

```php
final class BalanceService
{
    public function asAt(Business $b, string $accountCode, CarbonInterface $date): Money;
    public function movement(Business $b, string $accountCode, CarbonInterface $from, CarbonInterface $to): Money;
    public function position(Business $b, CarbonInterface $date): PositionSummary;
    public function dailyActivity(Business $b, CarbonInterface $date): DailyActivitySummary;
}
```

Four methods. Everything else composes them.

## Performance

Live queries for the current open period; `daily_closings` for anything historical.
A dashboard for a business with three years of history touches at most the current
month of `ledger_entries` plus a handful of `daily_closings` rows.

If it ever gets slower than that, the fix is a per-account daily balance table, not
a different architecture — because the ledger is still the source and the cache is
still rebuildable.

## Role-dependent views

- **App Owner** — a business selector plus a cross-business summary. Explicitly
  authorized, not the absence of a filter.
- **Business Owner** — everything above.
- **Entry Operator** — Band 2 and their own entries only. **No net position, no
  trends, no alerts.** An operator does not need the business's financial standing
  to do their job.
