# 01 — Understanding of the Business

## What this system is

A **management financial control layer** for pharmaceutical distribution businesses,
operated as a small multi-tenant platform by an App Owner.

It is not an ERP and it is not a replacement for the POS. The POS remains the
operational system — it handles item-level sales, printing, and day-to-day counter
work. This application answers a different question, one the POS was never designed
to answer:

> *Where does this business actually stand, financially, today?*

That question decomposes into four balances and a handful of daily movements:

| Balance | Nature | Confidence |
|---|---|---|
| Cash in hand | Pure transaction arithmetic | **Exact** |
| Market receivables | Pure transaction arithmetic | **Exact** |
| Company payables | Pure transaction arithmetic | **Exact** |
| Stock value | Derived from a profit figure produced elsewhere | **Estimate** |

That last row is the single most important fact about this design and it is
addressed at length in [02](02-accounting-model.md#stock-valuation) and
[03](03-problems-and-risks.md#stock-problems).

## The shape of the business

A pharmaceutical distributor buys from manufacturers ("companies") on credit,
warehouses the goods, sells to pharmacies mostly on credit, and collects the money
back from the market over the following weeks. Its entire working-capital problem
lives in the gap between two of those steps:

```
  Company ──purchase──▶ Stock ──sale──▶ Market receivable ──collection──▶ Cash ──payment──▶ Company
   (credit)              (asset)          (the risk)                       (the safety)     (the pressure)
```

Money leaves as a payable, sits as stock, becomes a receivable on sale, and returns
as cash. The business is solvent when the return leg keeps pace with the pressure
leg. This is why *daily change in market credit* matters as much as daily profit —
a distributor can be profitable on paper every day while quietly going broke,
because the profit is sitting in a pharmacy's ledger and not in the safe.

## Scope of Version 1

**In scope**

- Multi-business platform with three roles (App Owner, Business Owner, Entry Operator)
- Explicit opening balance with a draft → finalized → locked lifecycle
- Summary-level daily transaction entry (not invoice-level, not item-level)
- Transaction-derived balances for stock, cash, receivables, payables
- Daily closing with lock and audit trail
- Management position dashboard with trends and calculated alerts
- Full audit log with reversal/adjustment corrections

**Explicitly out of scope for v1, but architected for**

- Products, batches, expiry dates, item-level stock
- Individual customers and their ledgers
- Individual pharmaceutical companies and their ledgers *(Phase 8 — the ledger
  design already supports it without change; see [02](02-accounting-model.md#sub-ledgers))*
- Invoice-level sales and purchases
- Pharmacy mode — visible in the UI as **Coming Soon**, routed but not built

**Deliberately not built at all**

- Anything that lets a user type a balance directly. Every figure on the dashboard
  is the sum of transactions. This is the requirement the whole architecture exists
  to satisfy.

## Relationship to the POS

| Fact | System of record | How it reaches this app |
|---|---|---|
| Item-level sales, stock movement | POS | Not imported in v1 |
| Daily sales total, gross profit | POS report | Typed in by the operator during daily entry |
| Purchases from companies | Company invoices (paper) | Typed in |
| Market collections | Recovery sheets | Typed in |
| Company payments, expenses | This app | Entered here |
| **Financial position** | **This app** | Derived |

The two systems overlap on one number only — the daily sales total — and that
overlap is the seam where errors will enter. See
[03](03-problems-and-risks.md#reporting-problems).

## Non-goals worth stating

- This is not a statutory accounting system and will not produce a legal balance
  sheet or tax return in v1. The dashboard's position figure is a **management
  position**, and must be labelled as such.
- It will not attempt to reconcile item quantities. It reconciles *value*.
- It will not permit a user to "fix" a balance. Corrections are transactions.

## Version 1 is a back-office console

V1 is an internal tool, used by you (as App Owner) and, later, by a business owner
and an entry operator. There is no customer-facing surface, no pharmacy-facing
surface, and no public registration. That has three consequences worth stating,
because they shape where effort should go:

1. **Correctness beats polish.** The value of this build is entirely in whether the
   numbers are right. The UI should be plain, dense, fast, and responsive enough to
   use on a phone — nothing more. No design system, no component library, no SPA.
2. **The role architecture is still built now, even though one person uses it.**
   Retrofitting authorization into a financial system is far more expensive than
   building it in. But the *screens* for the other two roles can be minimal in v1.
3. **The multi-business architecture is also built now**, for the same reason. It is
   two columns and a global scope on day one, and a rewrite on day four hundred.

The front-facing application — distributor staff, pharmacy mode, anything
customer-visible — is a later phase built on the same ledger. Nothing in this design
needs to change to accommodate it, which is exactly the test a good foundation
should pass.
