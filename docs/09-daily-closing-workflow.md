# 09 — Daily Closing Workflow

## What closing is for

Closing is not a technical operation. It is the moment a human being looks at a day's
figures and agrees they are right. Every historical number in the system derives its
credibility from that act, which is why the close should be a **hard gate with a
checklist**, not a nightly cron job.

## Preconditions — the close is blocked until all are true

| Check | Why |
|---|---|
| Day `D−1` is closed (or `D` is the first day after `opening_date`) | A gap breaks the opening→closing chain and makes every later day unverifiable |
| No `draft` daily entry for day `D` | Closing a day with unposted data locks in a known-wrong figure |
| No `draft` transactions for day `D` | Same |
| Cash reconciliation completed | The one control that catches everything else |
| Any cash variance has a written reason | An unexplained variance is a fraud signal, not a rounding issue |

The checklist is shown on the closing screen with each item ticked or blocking, so
the operator can see exactly what is stopping them.

## The closing screen

```
  ┌─ Daily Closing — 02 September 2026 ───────────────────────────────────────┐
  │                                                                           │
  │  PRE-CLOSE CHECKS                                                         │
  │    ✓ Previous day (01-09-2026) closed                                     │
  │    ✓ No draft entries                                                     │
  │    ✓ No draft transactions                                                │
  │    ⚠ Cash not yet reconciled                            [ Reconcile now ] │
  │                                                                           │
  │  ─── CASH ──────────────────────────────────────────────────────────────  │
  │    Opening cash                                              93.00        │
  │    + Cash sales                                         120,000.00        │
  │    + Market collections                                  50,000.00        │
  │    − Cash purchases                                           0.00        │
  │    − Company payments                                   150,000.00        │
  │    − Expenses                                             5,000.00        │
  │    − Owner drawings                                           0.00        │
  │                                                        ────────────       │
  │    Closing cash (per ledger)                             15,093.00        │
  │    Physically counted                        [        15,093.00 ]         │
  │    Variance                                                   0.00  ✓     │
  │                                                                           │
  │  ─── MARKET ────────────────────────────────────────────────────────────  │
  │    Opening receivable                                   500,000.00        │
  │    + Credit sales                                       180,000.00        │
  │    − Collections                                         50,000.00        │
  │    − Sales returns / discounts / write-offs                   0.00        │
  │                                                        ────────────       │
  │    Closing receivable                                   630,000.00        │
  │    Change today                                        ▲ 130,000.00  ⚠    │
  │                                                                           │
  │  ─── COMPANIES ─────────────────────────────────────────────────────────  │
  │    Opening 3,200,000.00 + 129,000.00 − 150,000.00 = Closing 3,179,000.00  │
  │    Change today                                        ▼ −21,000.00       │
  │                                                                           │
  │  ─── STOCK ─────────────────────────────────────────────────────────────  │
  │    Opening 1,000,000.00 + 129,000.00 − 270,000.00 = Closing 859,000.00    │
  │    ⓘ Estimated. Last physically verified 28 days ago (drift −1.4%).       │
  │                                                                           │
  │  ─── RESULT ────────────────────────────────────────────────────────────  │
  │    Total sales                                          300,000.00        │
  │    Cost of goods sold                                   270,000.00        │
  │    Gross profit                                          30,000.00 (10.0%)│
  │    Operating expenses                                     5,000.00        │
  │    Net profit                                            25,000.00        │
  │                                                                           │
  │    Net position   −1,699,907.00  →  −1,674,907.00       ▲ 25,000.00       │
  │                                                                           │
  │                                  [ Reconcile cash first ]   [ Cancel ]    │
  └───────────────────────────────────────────────────────────────────────────┘
```

Every figure on this screen is read from the ledger through `BalanceService`. None
of it is typed except the physical cash count.

## Cash reconciliation

The most valuable control in the application, and the cheapest — and with
[D4](00-decisions.md) routing every rupee through physical cash, it is the *only*
independent check on the largest flow in the business. It is mandatory, not
optional.

The operator counts physical cash and enters it. The system compares. If they
differ, a reason is **required**, and the difference posts as a real transaction —
either to `6000 Operating Expenses` (cash short) or `4200 Other Income` (cash over),
or to `3100 Owner Drawings` where the owner confirms they took it.

What this achieves: it converts an invisible, compounding error into a visible daily
one. A business that reconciles cash nightly finds a problem within a day; one that
does not finds it a year later as an unexplainable 400,000 gap that destroys
confidence in every other number.

## Finalization

```php
DB::transaction(function () use ($business, $date, $user) {
    $this->guard->assertClosable($business, $date);      // all preconditions

    $figures = $this->closingService->compute($business, $date);   // from the ledger

    $closing = DailyClosing::updateOrCreate(
        ['business_id' => $business->id, 'business_date' => $date],
        [...$figures, 'status' => 'finalized',
         'finalized_by' => $user->id, 'finalized_at' => now(), 'built_at' => now()],
    );

    $business->update(['locked_through_date' => $date]);

    activity()->on($closing)->by($user)->log('closing.finalized');
});
```

After finalization:

- `businesses.locked_through_date = D`
- No transaction may be posted with `business_date <= D`
- The `daily_closings` row is read-only
- The next day's opening position is `D`'s closing position — **derived, not copied
  into an editable field**

## Next day's opening

Stated explicitly because it is where "no editable balances" is most easily
violated:

> Day `D+1`'s opening position is **not stored as data that anyone can change.** It
> is `BalanceService::asAt($business, $D)` — the same query that produced `D`'s
> closing. The `daily_closings` row stores the figures for speed and for audit, but
> `php artisan closings:rebuild --from=2026-09-01` must reproduce every one of them
> exactly from the ledger.

If a rebuild ever produces different numbers than the stored closing, that is a bug
and the dashboard must show it. Build that check into the nightly integrity command.

## Late entries and corrections

A company invoice dated the 1st arrives on the 4th, and the 1st is closed. Three
options exist; the design chooses deliberately rather than leaving it to chance:

| Option | Effect | Verdict |
|---|---|---|
| **Reopen the day** | History changes, but with a full audit trail and a re-close | **Recommended** while within the current month |
| **Post to the open day**, recording `original_business_date` | History immutable, but both days misstated | Fallback after month-end |
| **Refuse** | Clean; unusable in practice | No |

### Reopening

- Business Owner or App Owner only, reason required, fully audited
- Only the **most recent closed day** can be reopened, and only in sequence —
  reopening day `D` requires reopening `D+1 … today` first, so the chain is never
  broken
- Reopening rolls `locked_through_date` back to `D−1`
- The old `daily_closings` row is retained with `status = superseded` and a new one
  is created on re-close, so the audit trail shows both what was reported and what
  it became
- Blocked entirely once a month-end lock is applied

### Adjustments

For anything older, an `ADJUSTMENT` transaction dated in the current open period,
with a mandatory reason. The correction lands where it was discovered. Prior days
keep the figures that were reported at the time — which is correct, because those
figures were acted on.

## Month-end and year-end

Not required for v1, but the design should leave room:

- A **month lock** sets a harder boundary that no reopen can cross
- A **year-end close** would transfer income and expense balances to retained
  earnings and open the new year — the standard closing entry
- Because everything is a dated ledger, neither requires new machinery, only new
  transaction types

## Automation

Closing should **not** be automated. A cron job that closes days unattended
reintroduces exactly the problem the system exists to solve: numbers nobody looked
at. What *should* be automated:

- A nightly reminder when a day is unclosed
- A nightly integrity check (trial balance balances; rebuilt closings match stored)
- Alert recomputation
