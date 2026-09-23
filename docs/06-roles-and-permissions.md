# 06 — Roles & Permissions

## The structural decision

**The App Owner is not a business role.** They operate the platform; they are not a
member of any business. Modelling them as a role *inside* a business forces
"which business am I acting as?" logic into every query and every policy, and it is
the source of most multi-tenant authorization bugs.

```
   PLATFORM SCOPE                          BUSINESS SCOPE
  ┌───────────────────┐                   ┌──────────────────────────────────┐
  │ App Owner         │  creates ────────▶│ Business: ABC Pharma             │
  │ users.is_platform │                   │   ├── Business Owner  (member)   │
  │   _admin = true   │  creates ────────▶│   └── Entry Operator  (member)   │
  │                   │                   ├──────────────────────────────────┤
  │ no business       │  creates ────────▶│ Business: XYZ Pharma             │
  │ membership        │                   │   └── Business Owner  (member)   │
  └───────────────────┘                   └──────────────────────────────────┘
```

- Platform scope: `users.is_platform_admin`, checked by a `Gate::before` for
  platform abilities only — **never** as a blanket bypass.
- Business scope: `business_user.role`, expanded into permissions by
  `spatie/laravel-permission` with the teams feature, teams = businesses.

That distinction is why a user can be Owner of one business and Operator of another
without any special-casing.

## Permission architecture

No hard-coded role strings in controllers. Three layers, each doing a distinct job:

| Layer | Responsibility | Example |
|---|---|---|
| **Middleware** | Establishes and validates business context on every request | `SetCurrentBusiness` — resolves `business_id` from the session, asserts active membership, aborts 403 otherwise |
| **Global scope** | Makes cross-business data invisible by default | `BusinessScope` on every business-scoped model |
| **Policy** | Decides whether *this* user may do *this* to *this* record | `DailyEntryPolicy::update()` — false when the entry is posted, regardless of role |

Permissions are named as verbs on resources and grouped into roles, so new roles are
configuration rather than code:

```
business.view          business.configure       business.manage_users
opening_balance.view   opening_balance.create   opening_balance.finalize
daily_entry.view       daily_entry.create       daily_entry.post
closing.view           closing.finalize         closing.reopen
transaction.view       transaction.adjust       transaction.reverse
stock.verify
expense.create         expense.create_restricted
report.view            report.export
audit.view
```

## Role matrix

✅ allowed · ⚠️ allowed with constraints · ❌ denied

| Capability | App Owner | Business Owner | Entry Operator |
|---|:---:|:---:|:---:|
| **Platform** | | | |
| Create / configure businesses | ✅ | ❌ | ❌ |
| View all businesses | ✅ | ❌ | ❌ |
| Create users, assign roles | ✅ | ⚠️ own business, cannot create owners | ❌ |
| Activate / deactivate users | ✅ | ⚠️ own business | ❌ |
| System settings | ✅ | ❌ | ❌ |
| **Opening balance** | | | |
| Create / edit draft | ✅ | ❌ | ❌ |
| Review | ✅ | ⚠️ view only | ❌ |
| Finalize & lock | ✅ | ❌ | ❌ |
| Edit after finalization | ❌ | ❌ | ❌ |
| Correct via adjustment | ⚠️ reason required, audited | ❌ | ❌ |
| **Daily operations** | | | |
| Enter purchases / sales / collections / payments | ✅ | ✅ | ✅ |
| Enter expenses | ✅ | ✅ | ⚠️ permitted categories, capped amount |
| Post a daily entry | ✅ | ✅ | ✅ |
| Owner drawings / capital | ⚠️ | ✅ | ❌ |
| Bad-debt write-off | ⚠️ | ✅ | ❌ |
| Stock verification | ⚠️ | ✅ | ❌ |
| Backdate within open period | ✅ | ✅ | ⚠️ ≤ 2 days |
| **Closing** | | | |
| View closing preview | ✅ | ✅ | ✅ |
| Finalize a day | ✅ | ✅ | ❌ |
| Reopen a closed day | ✅ | ⚠️ reason required, audited | ❌ |
| Edit a finalized day | ❌ | ❌ | ❌ |
| **Corrections** | | | |
| Reverse a posted transaction | ⚠️ reason required | ✅ reason required | ❌ |
| Delete any financial record | ❌ | ❌ | ❌ |
| **Reporting** | | | |
| Dashboard, reports, exports | ✅ | ✅ | ⚠️ operational views only, no net position |
| Audit log | ✅ | ⚠️ own business | ❌ |

## Rules that apply to every role, including the App Owner

These are the ones worth being absolute about:

1. **No one can edit a posted transaction.** Not the App Owner, not in an emergency.
   The only mutation available anywhere in the system is a new transaction. A
   super-admin edit path destroys the credibility of every number the system
   produces, and it will be used.
2. **No one can hard-delete a financial record.** There is no delete route. Not
   hidden — absent.
3. **Every correction carries a reason**, validated as required, stored on the
   transaction and in the audit log.
4. **`business_id` is never accepted from the request.** It comes from validated
   session context, always.
5. **Denied authorization attempts on financial resources are logged.** A repeated
   403 pattern is the earliest signal of either a bug or a problem employee.

## Multi-business isolation — three independent layers

Isolation failures are silent and severe, so this is deliberately belt-and-braces:

```php
// 1. Global scope — cross-business rows are invisible by default
class BusinessScope implements Scope {
    public function apply(Builder $b, Model $m): void {
        if ($id = CurrentBusiness::id()) {
            $b->where($m->getTable().'.business_id', $id);
        }
    }
}

// 2. Scoped route bindings — /businesses/{business}/entries/{entry}
//    resolves {entry} only within {business}
Route::scopeBindings()->group(function () { /* … */ });

// 3. Policy — checked even when the scope already filtered
public function view(User $u, DailyEntry $e): bool {
    return $u->belongsToBusiness($e->business_id)
        && $u->can('daily_entry.view');
}
```

**Two additional rules that matter more than they look:**

- **Raw queries are banned on financial tables** outside `BalanceService`. A global
  scope does not apply to `DB::table()`, and reports are exactly where people reach
  for raw SQL. Every aggregate in the service takes `business_id` as a required
  argument — not an optional filter.
- **The App Owner's cross-business views are an explicit code path**, not the
  absence of a scope. `Business::withoutBusinessScope()` appears in exactly one
  place, behind a platform-admin gate, and it is reviewed.

## Testing the authorization layer

The following belong in the test suite from Phase 1, not added later:

- A user of business A receives 403 (not 404, not an empty page) for every route of
  business B.
- A posted transaction cannot be updated by any role, asserted per role.
- An operator cannot finalize a day.
- A finalized opening balance cannot be edited by the App Owner.
- Deactivating a membership immediately revokes access mid-session.
