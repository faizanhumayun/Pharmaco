# 08 — Daily Transaction Workflow

## What the operator sees

One form, one business date, grouped by what the person actually has in front of
them. Totals compute live; nothing that can be derived is ever typed.

```
  ┌─ Daily Business Entry ─────────────────── Business date: [ 02-09-2026 ] ──┐
  │                                                                           │
  │  PURCHASES (from companies)                                               │
  │    Credit purchases              [        150,000.00 ]                    │
  │    Cash purchases                [              0.00 ]                    │
  │    Trade discount received       [         21,000.00 ]  ← reduces stock cost
  │    Purchase returns / claims     [              0.00 ]                    │
  │    ────────────────────────────────────────────────────                   │
  │    Total purchases                        150,000.00   (computed)         │
  │    Cost added to stock                    129,000.00   (computed)         │
  │                                                                           │
  │  SALES                                                                    │
  │    Cash sales                    [        120,000.00 ]                    │
  │    Credit sales                  [        180,000.00 ]                    │
  │    Sales returns                 [              0.00 ]                    │
  │    Gross profit (from POS)       [         30,000.00 ]                    │
  │    ────────────────────────────────────────────────────                   │
  │    Total sales                            300,000.00   (computed)         │
  │    Cost of goods sold                     270,000.00   (computed)         │
  │    Margin                                     10.00%   (computed)  ✓      │
  │                                                                           │
  │  MARKET COLLECTIONS                                                       │
  │    Received in cash              [         50,000.00 ]                    │
  │    Discount allowed              [              0.00 ]                    │
  │                                                                           │
  │  COMPANY PAYMENTS                                                         │
  │    Paid in cash                  [        150,000.00 ]                    │
  │    Settlement discount received  [              0.00 ]                    │
  │    Paid to (note)                [ Getz, Abbott              ]            │
  │                                                                           │
  │  CASH MOVEMENTS                                                           │
  │    Operating expenses            [          5,000.00 ]  [+ itemize]       │
  │    Owner drawings                [              0.00 ]                    │
  │    Owner capital introduced      [              0.00 ]                    │
  │                                                                           │
  │  ─────────────────────────────────────────────────────────────────────    │
  │  RESULTING POSITION (preview, nothing posted yet)                         │
  │    Cash        93       →   15,093      ▼ Rs. 15,000                      │
  │    Receivable 500,000   →  630,000      ▲ Rs. 130,000   ⚠ growing         │
  │    Payable  3,200,000   → 3,200,000     — Rs. 0                           │
  │    Stock   1,000,000    →  859,000      ▼ Rs. −141,000                    │
  │    Net position −1,699,907 → −1,674,907  ▲ Rs. 25,000                     │
  │                                                                           │
  │                                          [ Save draft ]  [ Post entry ]   │
  └───────────────────────────────────────────────────────────────────────────┘
```

Two things about this screen matter more than the fields:

1. **Total sales and total purchases are computed, never entered.** Three inputs
   where only two are free is how internally inconsistent days get into a ledger.
2. **The resulting position is previewed before posting.** The operator sees the
   consequence of what they typed while they can still fix it. A 300,000 typo is
   obvious in the preview and invisible in the form.

## What the system does on post

A single `DailyEntry` document produces **a set of typed transactions**, each with
balanced entries. The document is the evidence; the transactions are the
interpretation.

Using the figures above:

| # | Transaction type | Debit | Credit | Amount |
|---|---|---|---|---:|
| 1 | `PURCHASE_CREDIT` | `1200` Stock | `2000` Company Payables | 129,000.00 |
| 2 | `SALE_CASH` | `1000` Cash | `4000` Sales | 120,000.00 |
| 3 | `SALE_CREDIT` | `1100` Receivables | `4000` Sales | 180,000.00 |
| 4 | `COGS` | `5000` Cost of Goods Sold | `1200` Stock | 270,000.00 |
| 5 | `COLLECTION` | `1000` Cash | `1100` Receivables | 50,000.00 |
| 6 | `COMPANY_PAYMENT` | `2000` Company Payables | `1000` Cash | 150,000.00 |
| 7 | `EXPENSE` | `6000` Operating Expenses | `1000` Cash | 5,000.00 |

*(The 21,000 is a trade discount, so cost added to stock is 129,000 rather than the
150,000 invoiced. There is no tax component — [D3](00-decisions.md).)*

Resulting balance movements:

```
  Cash      = 93 + 120,000 + 50,000 − 150,000 − 5,000        = 15,093
  Receivable= 500,000 + 180,000 − 50,000                     = 630,000
  Payable   = 3,200,000 + 129,000 − 100,000 − 50,000         = 3,179,000
  Stock     = 1,000,000 + 129,000 − 270,000                  = 859,000
  Gross profit  = 300,000 − 270,000                          =  30,000
  Net profit    = 30,000 − 5,000                             =  25,000
```

Note that every one of these is a *derived* figure — the system stores none of them.
Each is `SUM(debit) − SUM(credit)` over `ledger_entries` for the relevant account.

## Validation, and where it lives

All of this belongs in `StoreDailyEntryRequest` and the posting service, never in
the controller.

**Hard blocks**

- `business_date` must be later than `businesses.locked_through_date`
- `business_date` must not be in the future (business timezone)
- No existing posted `DailyEntry` for this business and date — enforced by the
  database unique constraint as well, because UI-level checks race
- All amounts `>= 0`
- Purchase discount cannot exceed the purchase amount
- Sales returns cannot exceed total sales for the day
- Entry Operators may not backdate more than 2 days

**Warnings the operator must acknowledge, not blocks**

- Gross margin outside 0–40% — the single input that drives stock valuation, so
  worth a speed bump
- Collections would drive market receivables negative (legitimate as an advance, but
  it belongs in `2100 Customer Advances`)
- Payments would drive company payables negative (legitimate as an advance →
  `1300`)
- Cash would go negative — almost always a data error, occasionally a genuine
  unrecorded capital injection
- Any single figure more than 3× its 30-day average

The distinction matters: blocks are for things that are definitely wrong, warnings
are for things that are usually wrong. Blocking a legitimate transaction teaches
people to work around the system.

## Atomicity

```php
public function handle(DailyEntryData $data, User $user): DailyEntry
{
    return DB::transaction(function () use ($data, $user) {
        $entry = DailyEntry::create([...$data->toArray(), 'status' => 'draft']);

        foreach ($this->posting->transactionsFor($entry) as $spec) {
            $this->ledger->post($spec);          // asserts Σdr = Σcr, throws on failure
        }

        $entry->update([
            'status' => 'posted', 'posted_by' => $user->id, 'posted_at' => now(),
        ]);

        return $entry;
    });
}
```

Synchronous, inside one transaction, no queues. A queued posting that fails leaves a
day with no ledger effect and no visible error, which is the worst possible failure
mode for an accounting system.

## Corrections

A posted daily entry cannot be edited. Two paths:

**Same day, mistake caught immediately** — reverse the whole entry and re-enter.
`ReverseDailyEntry` creates mirror transactions of type `REVERSAL`, links them both
ways, requires a reason, and frees the `(business_id, business_date)` slot for a new
entry. Both the original and the reversal stay visible.

**Later, after the day is closed** — an `ADJUSTMENT` transaction dated in the
current open period, with a reason. The prior day keeps the figures that were
reported at the time; the correction appears where it was discovered. See
[09](09-daily-closing-workflow.md#late-entries-and-corrections).

## Transactions outside the daily entry

Some things do not belong on a daily form and get their own small screens:

| Action | Who | Why separate |
|---|---|---|
| Bad-debt write-off | Business Owner | Deliberate, infrequent, needs a reason |
| Stock verification | Business Owner | Periodic, produces a variance posting |
| Fixed asset purchase | Business Owner | Must not land in expenses |
| Manual adjustment | Owner / App Owner | Correction path, always audited |

Each produces the same kind of typed transaction through the same `LedgerPoster`.
There is one way into the ledger.
