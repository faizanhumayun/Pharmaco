# 00 — Decisions Taken

Answers to the blocking questions, and what each one changes. Recorded here so the
rest of the documents can be read as settled rather than provisional.

| # | Question | Decision | Date |
|---|---|---|---|
| D1 | POS "Calculated Profit" basis | **Gross margin** — sales minus cost of goods, before operating expenses | 2026-09-01 |
| D2 | Ledger design | **Double-entry ledger** — accounts, transactions, balanced entries | 2026-09-01 |
| D3 | Sales tax | **Not registered** — tax, where it exists, is simply part of cost | 2026-09-01 |
| D4 | Bank accounts in v1 | **None** — the business runs on cash | 2026-09-01 |

---

## D1 · Profit is gross margin

`COGS = (Cash sales + Credit sales − Sales returns) − Gross profit`, and the result
debits `5000 Cost of Goods Sold` and credits `1200 Stock`.

**Consequence:** the daily entry field is labelled *Gross profit (sales − cost of
goods, before expenses)*, not "profit", so the definition is visible at the point of
entry rather than living in a document nobody reads. Net profit is reported
separately as gross profit − expenses − stock variance − bad debts.

**Still to verify before Phase 5:** check the POS report against this definition on
three real days. In particular, confirm whether its profit figure accounts for sales
returns, and whether any non-stock revenue (service charges, scrap) is included in
its sales total without a corresponding cost. Both would bias COGS.

---

## D2 · Double-entry ledger

Confirmed as designed. See [04](04-recommended-architecture.md).

---

## D3 · Not sales-tax registered

**Simplifies the design materially.** What changes from the original draft:

- No input-tax, output-tax or withholding-tax accounts. The chart of accounts loses
  nothing else.
- The `purchase_tax` field is **removed** from the daily entry form. The amount you
  are invoiced *is* the cost, and it debits `1200 Stock` in full.
- Sales figures are gross and need no tax adjustment, so `gross profit = sales − COGS`
  with no exclusion clause.
- Receivables and payables are simply the invoiced amounts.

**What still needs capturing, and is easy to conflate with tax:** *trade discounts*.
If a company invoice shows 200,000 less a 5% trade discount, the cost that debits
stock is 190,000, not 200,000. The daily entry keeps a **Purchase discount** field
for this. It is the one remaining way an invoice total differs from the cost added
to stock.

---

## D4 · No bank accounts in v1

Everything moves as physical cash: collections come in as cash, company payments go
out as cash, expenses are paid in cash.

**What changes:**

- The daily entry form has one money column instead of two. Collections, company
  payments and expenses are cash-only.
- The dashboard shows one cash figure rather than cash + bank.
- The daily closing has one cash section.
- `1010 Bank` is **still seeded in the chart of accounts** but is not postable, does
  not appear in any UI, and holds zero. This costs nothing now and means enabling a
  bank account later is a settings change plus a form field, not a migration and a
  reworking of every balance query. Say the word if you would rather it not exist at
  all.

**Two consequences worth being explicit about:**

1. **Cash reconciliation becomes the single most important control in the system.**
   With every rupee moving as cash, the nightly count is the only independent check
   on the largest flow in the business. It should be mandatory before a day can
   close, not optional. This was already the design; D4 raises the stakes.

2. **Cheques are now the open edge case.** If a pharmacy ever settles by cheque, or
   a company is ever paid by cheque or draft, there is nowhere for it to go —
   recording it as cash overstates cash until it clears, and a bounce corrupts both
   cash and receivables. Post-dated cheques are common in this trade, so this is
   worth confirming rather than assuming. See **Q6** in
   [13](13-open-questions.md).

   If cheques do occur even occasionally, the cheapest correct answer is a single
   `1020 Cheques in Hand` asset account and two extra actions (received, cleared or
   bounced) — considerably less work than a bank module, and it keeps the cash
   figure honest.

---

## Remaining open questions

D1–D4 unblock Phases 1 through 4. Still needed before their respective phases:
**Q5** owner drawings and capital, **Q6** cheques (raised in priority by D4),
**Q7** cash held overnight by recovery staff (also raised by D4), **Q8** stock
verification cadence, and **Q9–Q12**. See [13](13-open-questions.md).
