# Pharmaco

A financial control layer for pharmaceutical distribution businesses, built on Laravel 12.

The design is in [`docs/`](docs/README.md) — read [`docs/00-decisions.md`](docs/00-decisions.md)
and [`docs/03-problems-and-risks.md`](docs/03-problems-and-risks.md) first.

## Status

| Phase | | |
|---|---|---|
| 1 | Foundation, authentication, roles | **Done** |
| 2 | Business setup & shell | **Done** (brought forward with Phase 1) |
| 3 | Ledger core | **Done** |
| 4 | Opening balance | **Done** |
| 5 | Daily transaction entry | **Done** |
| 6 | Daily closing | **Done** |
| 7 | Dashboard & reports | **Done** |
| 8 | Stock verification | **Done** |
| 9 | Company sub-ledgers | **Done** |
| 10 | Audit & hardening | **Done** |

## Local setup

Requires PHP 8.2+, Composer, Node, and MySQL 8. These instructions assume MAMP,
whose MySQL listens on port 8889.

```bash
composer install
npm install && npm run build
cp .env.example .env && php artisan key:generate
```

Create the databases:

```bash
/Applications/MAMP/Library/bin/mysql80/bin/mysql -uroot -proot -h127.0.0.1 -P8889 \
  -e "CREATE DATABASE pharmaco; CREATE DATABASE pharmaco_test;"
```

Then:

```bash
php artisan migrate --seed
php artisan serve
```

Sign in as the seeded App Owner — `admin@pharmaco.test` / `password`. Change the
password immediately, or set `PLATFORM_ADMIN_EMAIL` and `PLATFORM_ADMIN_PASSWORD`
in `.env` before seeding.

## Tests

```bash
php artisan test
```

Check the integrity of every business ledger (meant to run nightly):

```bash
php artisan ledger:verify        # every business ledger balances and reconciles
php artisan closings:rebuild --check   # stored closings still match the ledger
```

Both run nightly via the scheduler. Day *closing* is deliberately not automated:
a cron job that closes days unattended reintroduces exactly the problem this
system exists to solve.

Tests run against MySQL (`pharmaco_test`), not SQLite, deliberately: the schema
relies on MySQL behaviour that SQLite does not reproduce, and a financial schema
should be tested on the engine it will actually run on.

## Two rules that shape everything

1. **No balance is ever stored as an editable value.** Every figure the
   application reports is an aggregation over the ledger. There is no
   `current_cash` column to correct, because there is no `current_cash` column.

2. **Posted financial records are immutable, for everyone.** Corrections are
   reversals and adjustments, never edits — including for the App Owner. A
   super-admin override would defeat the audit trail, and it would get used.
