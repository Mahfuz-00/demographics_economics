# Demographics & Economy Data Modules

Source-traceable, append-only historical data platform for country indicators.
**This pass implements the Demographics module only.** The Economy, Debt and Wealth
Indicator modules are designed for but not yet built — the platform tables
(`entities`, `indicators`, `sources`, `observations`) are module-agnostic so they
slot in without migration.

## Stack

| Layer | Technology |
|---|---|
| API + ingestion | Laravel 13 (PHP 8.4) |
| Database | PostgreSQL 16 |
| Frontend | React 18 + TypeScript + Vite 6 |
| Charts / tables | Recharts, TanStack Table |
| Data fetching | Laravel HTTP client (Guzzle); scheduled queue jobs |

## Layout

```
backend/     Laravel application (API + ingestion + CSV importer)
frontend/    React + TypeScript single-page app
docs/        Architecture, data dictionary, source registry
docker-compose.yml   Optional PostgreSQL 16 container (the app runs natively)
```

## Core principles

- **Append-only.** Observations are immutable. A corrected or newer value inserts a new
  revision and links to the row it replaces; nothing is overwritten.
- **Explicit missing data.** Unavailable figures are recorded as `status = missing` with a
  reason, never interpolated or silently treated as zero.
- **Provenance on every value.** Each observation stores publisher, source URL, reference
  period, publication date, retrieval timestamp and the raw payload snapshot it came from.
- **Source priority.** National statistics (1) > UN (2) > other international official (3) >
  Wikipedia (4) > manual/unverified (5). Disagreements are recorded, not silently resolved.
- **No derived sub-annual data.** Quarterly/monthly observations are only accepted from
  sources that publish them; annual series are never split or interpolated.

## Documentation

| Document | What it covers |
|---|---|
| [docs/getting-started.md](docs/getting-started.md) | Prerequisites, setup, ingest commands, running the tests |
| [docs/architecture.md](docs/architecture.md) | Schema, the append-only model, source priority, missing-data policy, ingestion |
| [docs/data-dictionary.md](docs/data-dictionary.md) | Every indicator, its unit and its caveats, plus how the spreadsheet maps onto it |
| [docs/source-registry.md](docs/source-registry.md) | Sources and their ranks, what each adapter does, how to add one |

## Getting started

See `docs/getting-started.md`.

```bash
# backend
cd backend
composer install
cp .env.example .env          # set DB_* for your PostgreSQL
php artisan key:generate
php artisan migrate --seed
php artisan demographics:import-csv database/seed-data/Demographics\(Demographic\).csv

# frontend
cd ../frontend
npm install
npm run dev                   # proxies /api -> http://127.0.0.1:8000
```
