# 04 — Recommended Architecture

## The one decision everything else follows from

> **One ledger. Many capture surfaces.**

There is a single financial engine — a chart of accounts, a transaction, and its
balanced entries — and everything else in the application is a *way of producing
transactions* or a *way of reading balances*.

```
   CAPTURE SURFACES                 ENGINE                    READ MODELS
  ┌────────────────────┐                                  ┌──────────────────┐
  │ Opening balance    │──┐                            ┌──│ Dashboard cards  │
  │ wizard             │  │     ┌──────────────┐       │  ├──────────────────┤
  ├────────────────────┤  │     │ transactions │       │  │ Daily closing    │
  │ Daily entry form   │──┼────▶│      +       │──────▶┼──├──────────────────┤
  │ (summary, v1)      │  │     │ ledger_      │       │  │ Company ledger   │
  ├────────────────────┤  │     │   entries    │       │  ├──────────────────┤
  │ Corrections /      │──┤     └──────────────┘       │  │ Trends, alerts   │
  │ adjustments        │  │      accounts              │  ├──────────────────┤
  ├────────────────────┤  │      (per business)        └──│ Reports, exports │
  │ Invoice-level      │──┘                               └──────────────────┘
  │ entry (v2, later)  │
  └────────────────────┘
```

Adding invoice-level purchasing later means adding a capture surface. Adding
per-company payables means adding accounts. Adding pharmacy mode means adding a
capture surface. **The engine and every read model stay untouched.** That property
is the entire justification for the design, and it is what the specification asks
for when it says the future company-payable module must not require rewriting the
financial engine.

## The three layers

### 1 · Capture — documents

A *document* is what the user thinks they are entering: an opening balance, a day's
business, a correction. It stores the user's inputs **verbatim**, exactly as typed,
plus who typed them and when.

This matters more than it looks. Keeping the raw inputs means that if the posting
rules are later found to be wrong, the days can be **re-posted from the original
data** rather than reconstructed from memory. A document is the evidence; the ledger
is the interpretation.

Documents have a lifecycle: `draft → posted → (reversed)`. Only posting writes to
the ledger. A posted document is immutable.

### 2 · Engine — the ledger

Two tables and one service.

- `transactions` — a header: business, business date, type, narration, source
  document, who, when, status.
- `ledger_entries` — the lines: account, debit, credit. Balanced per transaction.
- `LedgerPoster` — **the only class in the codebase that writes to `ledger_entries`.**

That last point is the enforcement mechanism for every accounting invariant. If one
class is the sole writer, its tests are the guarantee. If five classes write, there
is no guarantee.

### 3 · Read — derivation and snapshots

- `BalanceService` — the only class that answers "what is the balance of account X
  in business Y at date Z". Every card, report and export calls it. One derivation
  means the dashboard and the reports cannot disagree.
- `daily_snapshots` — a materialized cache of each closed day's figures, for fast
  trends. Rebuildable by artisan command, never written by anything else.

## What this buys, concretely

| Requirement from the spec | How the architecture satisfies it |
|---|---|
| "Balances calculated from transactions, not editable totals" | There is no balance column. It is not that editing is forbidden; there is nothing to edit. |
| "Total company payable ≡ sum of company ledgers" | Control account. Structurally true, not maintained. |
| "Future company module must not require rewriting the engine" | Sub-accounts under an existing control account. Zero engine change. |
| "Corrections use reversal/adjustment, not silent modification" | Posted transactions are immutable; the only mutation available is a new transaction. |
| "Opening balance is a distinct starting point" | A document of type `OPENING` producing a transaction of type `OPENING`. Distinct, auditable, and part of the same arithmetic. |
| "Historical records must not be corrupted" | Append-only ledger + period locks + values frozen on documents. |
| "Multi-business isolation" | `business_id` on every row, global scope, scoped bindings, policies. |

## What is deliberately *not* in the architecture

Being explicit about complexity that was considered and rejected:

- **No event sourcing.** The ledger is already an append-only event log for the
  only domain where it matters. Full event sourcing adds machinery without adding
  answers.
- **No CQRS split, no repositories over Eloquent.** Eloquent is the data layer.
  Services encapsulate the accounting, not the persistence.
- **No queued posting.** Postings are synchronous inside the same database
  transaction as the document. A queued posting that fails leaves a sale with no
  ledger effect and no visible error. Accounting must be atomic.
- **No SPA, no Livewire, no Inertia.** The v1 surfaces are ordinary forms and
  tables. Blade with Alpine for the few interactive bits (running totals on the
  daily-entry form) is sufficient and is the right call. Revisit only when
  invoice-level line-item grids arrive, which is the one place a reactive component
  genuinely earns its cost.
- **No packages beyond the essentials.** `spatie/laravel-permission` for roles,
  `spatie/laravel-activitylog` for audit, `barryvdh/laravel-dompdf` when PDFs are
  needed. Resist more.
- **No microservices, no separate reporting database.** A single MySQL schema with
  correct indexes handles a distributor's transaction volume by three orders of
  magnitude.

## Data flow of a single day, end to end

```
  Operator submits daily entry form
        │
        ▼
  StoreDailyEntryRequest        validates: totals reconcile, date is in an open period,
        │                       no existing entry for this business + date
        ▼
  RecordDailyEntry (action)     ── DB::transaction ──────────────────────────────┐
        │                                                                        │
        ├──▶ DailyEntry::create()          the document, inputs stored verbatim  │
        │                                                                        │
        ├──▶ DailyEntryPostingService      translates inputs → transaction set    │
        │         │                                                              │
        │         ├── PURCHASE_CREDIT ─┐                                          │
        │         ├── PURCHASE_CASH  ──┤                                          │
        │         ├── SALE_CASH      ──┤                                          │
        │         ├── SALE_CREDIT    ──┼──▶ LedgerPoster::post()                  │
        │         ├── COGS (derived) ──┤         asserts Σdr = Σcr                │
        │         ├── COLLECTION     ──┤         writes transactions + entries    │
        │         ├── COMPANY_PAYMENT ─┤                                          │
        │         └── EXPENSE        ──┘                                          │
        │                                                                        │
        └──▶ activity log entry                                                  │
                                  ── commit ─────────────────────────────────────┘
        │
        ▼
  DailyEntryPosted event  →  (after commit)  →  recompute alerts, notify
```

Note the ordering: everything financial is inside one transaction; the event fires
after commit and does nothing the balances depend on.
