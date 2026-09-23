# 07 — Opening Balance Workflow

## Why this is the highest-risk step in the project

Every number the system will ever produce is `opening + Σ(transactions)`. If the
opening is wrong, every figure is wrong forever, and — because the error is constant
rather than growing — it is almost impossible to detect later. Cutover deserves to
be treated as a project phase, not a data-entry screen.

## Lifecycle

```
   ┌─────────┐  App Owner enters &   ┌───────────┐  one-way, on   ┌────────┐
   │  DRAFT  │  reviews, may edit    │ FINALIZED │  first close   │ LOCKED │
   │         │──────────────────────▶│           │───────────────▶│        │
   └─────────┘  freely, no ledger    └───────────┘                └────────┘
        │        effect                    │                           │
        │                                  │  posts the OPENING        │  corrections only via
        └── may be deleted while draft     │  transaction              │  a dated ADJUSTMENT
                                           │  business.status→active   │  transaction, audited
                                           └── business.opening_date set
```

- **DRAFT** — values editable by the App Owner. Nothing has been posted. No ledger
  effect. May be deleted.
- **FINALIZED** — the `OPENING` transaction is posted. The record becomes read-only.
  `businesses.opening_date` and `status = active` are set. Daily entry becomes
  available.
- **LOCKED** — set automatically when the first daily closing is finalized. From
  this point the opening balance is part of history; even the App Owner cannot
  touch it, and corrections require an `ADJUSTMENT` transaction dated in the open
  period, with a reason, fully audited.

Finalization is irreversible by design. There is no un-finalize route.

## Step 1 — Business information

App Owner creates the business: name, `business_type` (distributor; pharmacy shows
as **Coming Soon** and is not selectable), timezone, currency, address, contact.

On creation the system automatically:
- seeds the full chart of accounts for that business from
  [02](02-accounting-model.md#chart-of-accounts-v1), with fixed codes
- sets `status = setup` — daily entry is unavailable until an opening balance exists
- creates the first Business Owner user, or invites them

## Step 2 — Opening financial position

The four figures from the specification, **plus the ones without which the position
will not be true**. The extra fields are optional and default to zero, so the simple
case stays simple, but they must exist or the balancing figure absorbs them
silently:

| Field | Account | Required |
|---|---|---|
| Opening Stock Value | `1200` Stock | yes |
| Cash in Hand | `1000` Cash | yes |
| ~~Bank Balance~~ | `1010` Bank | *Not shown — [D4](00-decisions.md): all cash* |
| Market Receivables | `1100` Receivables | yes |
| Company Payables | `2000` Payables | yes |
| Advances paid to companies | `1300` | optional |
| Customer advances held | `2100` | optional |
| Fixed assets (vehicles, equipment) | `1400` | optional |
| Owner capital already invested | `3000` | optional |

Each field carries a note explaining *as at what moment* it is measured — the close
of business on the opening date — because "opening stock" measured on a different
day than "opening cash" is the most common cutover error.

### Recommended preparation, shown as a checklist on the screen

- [ ] Physical stock counted and valued **at cost**, not at sale price
- [ ] Market receivables agreed against customer statements, not estimated
- [ ] Company payables agreed against company statements
- [ ] Cash physically counted on the opening date
- [ ] All figures measured as at the **same** date

## Step 3 — Review

The review screen shows the entered figures, and — critically — **the derived net
position and the balancing figure**, before anything is committed.

Using the specification's own numbers:

```
  ASSETS
    Opening Stock                                    Rs.  1,000,000.00
    Cash in Hand                                     Rs.         93.00
    Market Receivables                               Rs.    500,000.00
                                                     ─────────────────
    Total Assets                                     Rs.  1,500,093.00

  LIABILITIES
    Company Payables                                 Rs.  3,200,000.00
                                                     ─────────────────
    Total Liabilities                                Rs.  3,200,000.00

  ═══════════════════════════════════════════════════════════════════
    NET POSITION                                     Rs. −1,699,907.00
  ═══════════════════════════════════════════════════════════════════

  ⚠  This opening position is negative. Liabilities exceed assets by
     Rs. 1,699,907.00. This is either a real finding that the business
     owner needs to know about, or the opening position is incomplete —
     most commonly unrecorded fixed assets, cash or stock held
     elsewhere, or stock valued below cost.

     Please confirm or revise before finalizing.  [ Revise ]  [ Explain ]
```

### The balancing figure

The difference between assets and liabilities has to go somewhere for the entry to
balance. It posts to `3900 Opening Balance Equity`.

**Do not let this account be a silent plug.** The design rule is:

- Any balance in `3900` above a threshold (say 5% of total assets, configurable)
  raises a warning on the review screen and **requires a written explanation**
  stored in `opening_balances.notes`.
- The `3900` balance remains visible on the dashboard's position breakdown until it
  is explained or corrected. It does not get folded into equity and forgotten.

This turns the most dangerous number in the system into the most visible one. In the
example above, −1,699,907 is 113% of total assets — the system should be shouting.

## Step 4 — Confirmation and finalization

The App Owner must tick, with the exact text stored on the record:

> *"I confirm that these values represent the actual opening financial position of
> this business at the time it started using the system."*

Then **[ Finalize Opening Balance ]**, which executes in a single database
transaction:

```php
DB::transaction(function () use ($openingBalance, $user) {
    $txn = $this->ledger->post(
        business:     $openingBalance->business,
        date:         $openingBalance->opening_date,
        type:         TransactionType::Opening,
        narration:    'Opening financial position',
        source:       $openingBalance,
        lines:        $this->linesFrom($openingBalance),   // includes the 3900 balancer
        createdBy:    $user,
    );

    $openingBalance->update([
        'status'           => OpeningBalanceStatus::Finalized,
        'transaction_id'   => $txn->id,
        'finalized_by'     => $user->id,
        'finalized_at'     => now(),
        'total_assets'     => $assets,
        'total_liabilities'=> $liabilities,
        'net_position'     => $assets->minus($liabilities),
    ]);

    $openingBalance->business->update([
        'opening_date' => $openingBalance->opening_date,
        'status'       => BusinessStatus::Active,
    ]);

    activity()->on($openingBalance)->by($user)
        ->withProperties(['values' => $lines, 'confirmation' => $text])
        ->log('opening_balance.finalized');
});
```

The resulting journal, for the example figures:

| Account | Debit | Credit |
|---|---:|---:|
| `1200` Stock | 1,000,000.00 | |
| `1000` Cash in Hand | 93.00 | |
| `1100` Market Receivables | 500,000.00 | |
| `3900` Opening Balance Equity | 1,699,907.00 | |
| `2000` Company Payables | | 3,200,000.00 |
| **Total** | **3,200,000.00** | **3,200,000.00** |

## Step 5 — Confirmation screen

> **Opening balance finalized on 1 September 2026 by Faizan Humayun.**
>
> This business's financial tracking begins on 1 September 2026. All balances from
> this date forward are calculated from recorded transactions and cannot be edited
> directly. Corrections require an adjustment transaction.

With links to: view the opening journal, download it as PDF, and begin daily entry.

## Corrections after finalization

There is no edit path. If the opening stock was 1,000,000 and should have been
1,200,000:

1. Business Owner or App Owner creates an `ADJUSTMENT` transaction, dated in the
   current open period, with a mandatory reason:
   *"Opening stock understated — Godown 2 not included in the count of 01-09-2026."*
2. Posting: `Dr 1200 Stock 200,000 / Cr 3900 Opening Balance Equity 200,000`.
3. Both the original opening entry and the adjustment remain visible, linked, in the
   audit log and in the account statement.

The correction lands in the period it was discovered, which is correct: prior closed
days keep the figures that were reported at the time, and the change is explained
rather than erased.
