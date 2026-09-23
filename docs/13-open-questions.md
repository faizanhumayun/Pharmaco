# 13 — Questions Requiring Your Decision

Only the questions where a different answer produces a different schema or different
arithmetic. Everything else I can decide sensibly and you can adjust later without
a migration.

> **Q1–Q4 are answered.** See [00 — Decisions Taken](00-decisions.md) for what each
> one changed. They are kept below for the record, marked with their answer.
> **Q6 and Q7 are raised in priority** by the decision to run entirely on cash.

---

## Answered — Phases 1 to 4 are unblocked

### Q1 · What is the POS's "Calculated Profit"?  →  **Gross margin**

Sales minus cost of goods, before operating expenses. `COGS = Net sales − Gross
profit`. The daily entry field will be labelled with that definition rather than the
word "profit", so it is visible at the point of entry.

*Still verify before Phase 5:* check the POS report on three real days — specifically
whether its profit figure accounts for sales returns, and whether any non-stock
revenue sits in its sales total without a matching cost. Either would bias COGS.

---

### Q2 · Ledger design?  →  **Double-entry**

Confirmed. Accounts, transactions, balanced entries, one posting service, one balance
service. See [04](04-recommended-architecture.md).

---

### Q3 · Sales tax?  →  **Not registered — tax is part of cost**

Simplifies the design. No tax accounts, no `purchase_tax` field, no "excluding tax"
qualifier on any sales figure. The invoiced amount is the cost.

**One thing this does not cover:** *trade discounts*. An invoice of 200,000 less 5%
adds 190,000 of cost to stock, not 200,000. A **Purchase discount** field stays on
the daily entry — it is now the only way an invoice total differs from stock cost.

---

### Q4 · Bank accounts?  →  **None in v1 — the business runs on cash**

One money column instead of two, on the entry form, the closing screen and the
dashboard. `1010 Bank` stays seeded but non-postable and hidden, so enabling it later
is a form field rather than a migration.

**Two consequences, both of which raise questions below in priority:**

1. Cash reconciliation is now the *only* independent check on the largest flow in the
   business. It becomes mandatory before a day can close, not optional.
2. Cheques have nowhere to go. See **Q6**, which moves from "nice to know" to the
   main open edge case in the design.

---

## Important — needed before Phase 5

### Q5 · Owner drawings and capital injections

Does the business owner take cash from the business for personal use, and put money
in? If yes (it usually is), these need first-class actions or the cash balance will
never reconcile and someone will invent fake expenses to explain the gap.

**Recommendation:** include them. The cost is two transaction types and two fields.

---

### Q6 · Cheques  —  raised in priority by D4

**Now the main open edge case.** With no bank account and everything moving as cash,
a cheque received or issued has nowhere to record it. Booking one as cash overstates
cash until it clears, and a bounce corrupts both cash and receivables.

Post-dated cheques are common in pharmaceutical distribution, so this is worth
confirming rather than assuming. Are collections ever received as cheques, or
companies ever paid by cheque or draft?

- **(a)** No — collections are cash or cleared transfer
- **(b)** Yes, but record them only when they clear ← *simplest, acceptable for v1*
- **(c)** Yes, and I need to see cheques in hand as a separate figure ← *a single
  `1020 Cheques in Hand` asset account plus received and cleared/bounced actions.
  Considerably less work than a bank module, and it keeps the cash figure honest.*

---

### Q7 · Cash held overnight by recovery staff  —  raised in priority by D4

Do recovery officers hold collected cash overnight, or is everything handed in the
same day? In an all-cash business this determines whether the nightly count can ever
match the ledger. If they hold it, "cash in hand" will never match the safe unless each
officer has a float account.

- **(a)** Same-day hand-in — no float accounts needed in v1 ← *assumed*
- **(b)** Cash is held overnight — needs `1030 Recovery Float` accounts, one per
  officer, so "cash in hand" means cash you can actually count in the safe

---

### Q8 · Stock verification cadence

How often can the business realistically do a physical stock valuation?

- **(a)** Monthly ← *recommended; keeps drift bounded and explainable*
- **(b)** Quarterly ← *the minimum I would defend*
- **(c)** Annually or never ← *then the stock figure should carry a prominent health
  warning and net position should arguably be shown without stock as well*

This determines the alert threshold and how confidently the dashboard can present
stock value. See [02](02-accounting-model.md#stock-valuation).

---

## Useful — answer before their phase

### Q9 · When do you want per-company payables?

Phase 9 as scheduled, or earlier? The architecture supports either. Knowing now
whether it is "soon" or "someday" tells me whether to capture company names as
free text on payments from Phase 5, which gives you history to migrate rather than a
blank when the module lands.

**Recommendation:** capture the free-text name from day one regardless. It costs one
column.

---

### Q10 · Late entries after a day is closed

Which do you want as the default?

- **(a)** Reopen the day, re-close it, full audit trail ← *recommended within the
  current month*
- **(b)** Post to the current open day, recording the original date
- **(c)** Both, with (a) inside the month and (b) after

---

### Q11 · Expense detail

Is a single daily expense total enough, or do you want expenses categorised
(freight, salaries, rent, fuel, utilities)? Categories cost almost nothing to add
now and are painful to backfill later, so I have designed for optional itemization —
but confirm whether you will actually use it.

---

### Q12 · Opening position — the −1,699,907

Not an architecture question, but the most important one on this page. The opening
figures in your specification describe assets of 1,500,093 against liabilities of
3,200,000.

Is that:

- **(a)** Real — the business is trading substantially on supplier credit, and this
  is exactly the visibility you built the app to get
- **(b)** Illustrative numbers, not the actual position
- **(c)** Incomplete — there are bank balances, vehicles, deposits or other assets
  not in the list, or stock is valued below cost

If **(c)**, the additional opening fields in
[07](07-opening-balance-workflow.md#step-2--opening-financial-position) matter more
than they might look, because whatever is missing will otherwise sit in
`3900 Opening Balance Equity` forever and quietly distort the net position on every
screen. Note that with no bank account ([D4](00-decisions.md)), the usual first
candidate for a missing asset is off the table — which makes unrecorded fixed assets
or undervalued stock the more likely explanations.
