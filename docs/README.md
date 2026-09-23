# Pharmaco — Architecture & Accounting Design

Design documents for the Pharmaceutical Distribution Management System (Laravel 12).

**Status:** proposal. Four blocking decisions taken; no application code written yet.

## Documents

| # | Document | What it covers |
|---|----------|----------------|
| 00 | [Decisions Taken](00-decisions.md) | The four settled decisions and what each changed — **read alongside 03** |
| 01 | [Understanding of the Business](01-business-understanding.md) | What the system is, what it is not, scope of v1 |
| 02 | [Accounting Model](02-accounting-model.md) | Chart of accounts, every transaction type, how each balance is derived |
| 03 | [Problems & Risks](03-problems-and-risks.md) | Critical review of the specification — read this first |
| 04 | [Recommended Architecture](04-recommended-architecture.md) | The core design decision and why |
| 05 | [Database Design](05-database-design.md) | Tables, columns, types, keys, indexes, constraints |
| 06 | [Roles & Permissions](06-roles-and-permissions.md) | Three roles, authorization architecture, multi-business isolation |
| 07 | [Opening Balance Workflow](07-opening-balance-workflow.md) | Business creation through finalization and locking |
| 08 | [Daily Transaction Workflow](08-daily-transaction-workflow.md) | What the operator enters, what the ledger does |
| 09 | [Daily Closing Workflow](09-daily-closing-workflow.md) | Closing, locking, late entries, corrections |
| 10 | [Dashboard Architecture](10-dashboard-architecture.md) | Every card and where its number comes from |
| 11 | [Laravel 12 Architecture](11-laravel-architecture.md) | Models, services, actions, policies, requests |
| 12 | [Development Phases](12-development-phases.md) | Ten phases, each independently shippable |
| 13 | [Open Questions](13-open-questions.md) | Decisions required before Phase 4 |

## The two things to take away

1. **Cash, receivables and payables will be exact. Stock will be an estimate.**
   The first three are pure transaction arithmetic with no modelling assumptions.
   Stock is derived from a profit figure produced by another system and accumulates
   error forever unless physically verified. The dashboard must not present them
   with equal confidence. See [02](02-accounting-model.md#stock-valuation) and
   [03](03-problems-and-risks.md#stock-problems).

2. **The opening figures in the specification describe an insolvent business.**
   Assets 1,500,093 against liabilities 3,200,000 is a net position of
   **−1,699,907 PKR**. That is either a real and urgent finding, or the opening
   position is incomplete — most likely missing bank balances, fixed assets, or
   understated stock. It must be resolved before finalization, not discovered
   afterwards. See [07](07-opening-balance-workflow.md#the-balancing-figure).
