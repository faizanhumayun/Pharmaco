# 12 — Recommended Development Phases

Ten phases. Each ends at a point where the application is coherent and demonstrable —
no phase leaves the system in a state where the numbers are half-right.

The ordering has one non-negotiable rule: **the ledger (Phase 4) is built before any
screen that produces financial data (Phase 5).** Building daily entry against
provisional balance logic and retrofitting the ledger afterwards is the failure mode
this whole design exists to avoid.

---

### Phase 1 — Foundation, authentication, roles  ✅ **Done**

Laravel 12 install, Breeze (Blade), `users` with `is_platform_admin`, `businesses`,
`business_user`, Spatie permissions with teams, `SetCurrentBusiness` middleware,
`BelongsToBusiness` trait and global scope, base policies, `Money` value object.

**Done when:** two businesses exist, a user of one gets 403 on every route of the
other, and there is a passing test proving it.

*Build the isolation tests here. They are cheap now and archaeological later.*

---

### Phase 2 — Business setup & the shell  ✅ **Done** — brought forward and built with Phase 1

App Owner console: create, configure, list, suspend businesses. Chart of accounts seeded per business on
creation *(the seeding hook exists in `CreateBusiness`; it fills in once Phase 3
creates the `accounts` table)*. Application layout, navigation, business switcher.
Pharmacy mode routed to a **Coming Soon** page behind a gate.

**Done when:** a business can be created and its 20 seeded accounts inspected.

---

### Phase 3 — Ledger core  ✅ **Done**

`accounts`, `transactions`, `ledger_entries`. `LedgerPoster` with the balance
assertion and the closed-period guard. `BalanceService` with its four methods.
Immutability enforcement on the models. `VerifyTrialBalance` command.

No UI beyond a read-only account statement view for developer sanity.

**Done when:** transactions can be posted from a test, balances derive correctly, an
unbalanced posting throws, and a posted transaction cannot be updated by any role.

*This is the phase to take slowly. Everything downstream inherits its correctness.*

---

### Phase 4 — Opening balance  ✅ **Done**  ← **next**

`opening_balances` + `opening_balance_lines`. The three-step wizard. The
draft → finalized → locked lifecycle. The `3900` balancer with its
unexplained-variance warning. Finalization posting the `OPENING` transaction.

**Done when:** the specification's own figures can be entered end to end and the
review screen correctly flags the −1,699,907 net position before anything is posted.

---

### Phase 5 — Daily transaction entry  ✅ **Done**

`daily_entries`, the entry form with computed totals and the live position preview,
`DailyEntryPostingService`, validation rules and warnings, reversal path,
`expense_categories`.

**Done when:** a day can be entered, previewed, posted, and reversed, and the
resulting balances match a hand-calculated expectation exactly.

---

### Phase 6 — Daily closing  ✅ **Done**

`daily_closings`, `ClosingGuard` and its checklist, the closing screen, cash
reconciliation with mandatory variance reason, finalization setting
`locked_through_date`, the reopen path with sequential enforcement, and
`RebuildClosings` + `CheckIntegrity` commands.

**Done when:** `closings:rebuild` reproduces every stored closing figure exactly,
and a posting into a closed day is rejected for every role including App Owner.

---

### Phase 7 — Dashboard & reports  ✅ **Done**

The three bands, the position panel with its second-derivation check, today's
activity, trend charts from `daily_closings`, the alert engine, role-dependent
views, CSV/PDF export.

**Done when:** every card links to the transactions behind it, and the operator view
correctly hides net position.

---

### Phase 8 — Stock verification & confidence  ✅ **Done**

`stock_verifications`, the verification screen, variance posting to `5100`, the
stock-confidence indicator on the dashboard and closing screen, the
unverified-for-45-days alert.

**Done when:** a verification posts a variance and the stock card shows its age.

*This phase is what makes the derived-COGS model defensible. It is not optional and
it should not slip.*

---

### Phase 9 — Company sub-ledgers  ✅ **Done**

`companies` table populated, child accounts under `2000`, existing aggregate
postings moved to `2000-000 Unallocated`, company selection on payments and
purchases, per-company statements with running balance, the reconciliation assertion
`balance(2000) = Σ balance(2000-*)`.

**Done when:** the total payable equals the sum of company balances by construction,
proven by a test, with no change to the ledger engine.

*Bring this forward if per-company visibility turns out to matter more than the
dashboard polish in Phase 7 — the architecture supports either order.*

---

### Phase 10 — Audit, hardening, operations  ✅ **Done**

Full activity log coverage with reasons, the audit viewer, failed-authorization
logging, rate limiting on financial POSTs, idempotency keys, backup and restore
runbook, Larastan level 6 on `app/Domain`, the full invariant suite in CI and as a
nightly production check.

**Done when:** the nightly integrity command runs clean and alerts on failure.

---

## Sequencing notes

**What can run in parallel:** Phase 7's chart work and Phase 8 are independent.
Phases 1–6 are strictly sequential.

**What must not be deferred past its phase:**

- Business isolation tests (Phase 1) — retrofitting authorization into a financial
  system is disproportionately expensive
- Immutability enforcement (Phase 3) — every day it is missing is a day of history
  that might have been edited
- The rebuild command (Phase 6) — without it, `daily_closings` quietly becomes a
  second source of truth

**The natural first release** is Phases 1–7. That is a complete, honest financial
control system: opening position, daily entry, closing, and a dashboard that answers
all nine of the original questions. Phases 8–10 harden it.

**Rough shape of effort**, assuming one developer: Phases 1–3 are a third of the
work and produce nothing visible, which is normal and worth expecting. Phases 4–7
are half. Phases 8–10 are the remainder and can be paced against real usage.

## Before Phase 4

[Q1–Q4 in the open questions](13-open-questions.md) must be answered. They change
the schema, and answering them after Phase 5 means a data migration on live
financial records.
