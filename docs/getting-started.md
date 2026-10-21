# Getting started

## Prerequisites

| Tool | Version used | Notes |
|---|---|---|
| PHP | 8.4 | `pdo_pgsql`, `curl`, `mbstring`, `dom`, `zip` required |
| Composer | 2.x | |
| PostgreSQL | 16 | The schema uses `jsonb`, a SQL view, `FILTER` and `ILIKE` — SQLite will not work |
| Node | 22 | |
| npm | 10 | |

## 1. Database

```bash
createdb demographics_economy
createdb demographics_economy_test     # used by the test suite
```

Or, if you prefer containers: `docker compose up -d db`.

## 2. Backend

```bash
cd backend
composer install
cp .env.example .env          # then set DB_*
php artisan key:generate
php artisan migrate --seed
```

`migrate --seed` loads reference data only: 259 entities (ISO 3166-1 countries and
territories plus region/aggregate rows), 34 indicators, 4 sources and their datasets,
61 national statistical offices, and the spreadsheet column mappings.

Then ingest the spreadsheets:

```bash
php artisan demographics:import-csv --all
```

That imports all four hand-maintained files — demographics, economy, debt and wealth —
each against its own column layout. One at a time is `--dataset=manual_economy_csv`, and
`php artisan demographics:import-csv --help` lists the codes.

Expect roughly 6,659 observations, 249 entities touched, 395 values flagged as having an
assumed period, and around 22 quarantine groups. Spot-check India's 2023 population
(`1,438,069,596`) and Japan's peak (`128,083,960`, dated 2008) in the UI.

The 395 assumed-period values are the right-hand tables of the sheet (divorce,
single-parent households, non-marital births, childlessness, sex-work estimates). They
carry figures but no reference year, so they are dated to the spreadsheet's compilation
year and marked as assumed rather than being dropped.

## 3. Calculated values

```bash
php artisan demographics:derive                 # every country and year
php artisan demographics:derive --entity=IND,USA
```

Fills the rates, flows and counts that follow arithmetically from the vital statistics:

- **Rates and flows** — crude birth and death rates, births less deaths, annual population
  change, net migration, the share of population change that is natural rather than
  migration, and the year-on-year percentage changes in births, deaths and natural change.
- **Absolute counts** — where a source publishes only a crude rate, the count is
  reconstructed from it (`rate / 1000 × population`), because that is what a crude rate
  means. This is what gives nearly every country births, deaths and net change for every
  year rather than only the handful whose articles carry a full table; roughly 13,500 of
  the ~14,500 birth counts are reconstructed rather than published.
- **Peaks** — peak population, peak GDP, peak GDP per capita and peak wealth are the
  maximum of their series, dated to the year they occurred. The spreadsheet carried
  pre-computed peak columns and they had drifted badly: 159 of 249 stored population peaks
  were *below* a population already held for that country, and 81 of 100 GDP peaks
  likewise once the IMF series was added. A peak lower than an observed value is stale
  arithmetic, not a competing opinion, so the recomputed value replaces it. The replaced
  row is kept and pointed at from the new one, so nothing is lost.

Each one is written only where no source has published that figure for that indicator and
period, is flagged `is_derived`, and is attributed to the low-ranked `derived_calculations`
source, so it can never displace a published value — a national office's own birth count
always wins over one back-calculated from a rate. The note on each observation states the
formula it used, and says so when its inputs were themselves reconstructed. Re-running is a
no-op.

## 4. Live sources

```bash
php artisan demographics:ingest --list        # what is configured
php artisan demographics:ingest world_bank    # ~2,000 requests' worth in 10 calls
php artisan demographics:ingest wikipedia
php artisan demographics:ingest un_wpp
```

The World Bank backfill from 1960 produces roughly 140,000 observations and takes a
few minutes; it is a background job in normal operation.

- **World Bank** works with no configuration.
- **Wikipedia** works with no configuration but is rate-limited and slow; limit it
  while testing with `"max_articles": 20` in the dataset's `config` JSON column.
- **UN WPP** needs either `UN_DATAPORTAL_API_KEY` plus `api.indicators` mapping in the
  dataset config, or a WPP bulk CSV placed in `storage/app/ingestion/un_wpp/`. Without
  one of those the run fails with instructions rather than silently collecting nothing.

### Vital statistics for one country (or a few)

The richest per-country history comes from each country's **"Demographics of <country>"**
article. Scrape one or several:

```bash
php artisan demographics:ingest wikipedia_vital_statistics --entity=IND
php artisan demographics:ingest wikipedia_vital_statistics --entity=USA,NLD,KOR
php artisan demographics:ingest wikipedia_vital_statistics --limit=10   # first 10 by name
```

Or press **Get data from source** on a country page, which runs the same scrape for that
one country. If nothing usable is found, a web search runs and the results are queued on
the **Data quality → Source candidates** tab for review — nothing from the search is
stored as data.

### What has been ingested

The store is currently built from these runs. Re-running any of them is a no-op unless the
upstream data has changed.

| Run | Result |
| --- | --- |
| `demographics:import-csv --all` | four spreadsheets, ~10,500 observations |
| `demographics:ingest world_bank_wdi_demographics` | 136,349 values, all already present on re-run |
| `demographics:ingest wikipedia_vital_statistics --entity=<40 largest>` | 21,861 observations, 13,670 disagreements recorded |
| `demographics:derive` | ~46,000 calculated values |

The Wikipedia scrape covered the 40 most populous countries and took published birth and
death counts from 8 to 45 countries. Where its figures disagree with the World Bank's, both
are kept and the higher-ranked source is served — the disagreements are on **Data quality**,
not silently resolved.

To extend coverage country by country:

```bash
php artisan demographics:ingest wikipedia_vital_statistics --limit=50
```

Then re-run `demographics:derive`, which fills the gaps the new counts open.

### Correcting a value

Signed in as an administrator, each row in a country's **All data** table has an **edit**
action. A correction is stored as a curated revision: it is served ahead of every
ingested source, the value it replaces stays on the record, and the reason is kept with
it.

### The two table views

- **Table** (`/table`) — one row per country, one column per indicator, each cell that
  country's latest known value, hover for its reference period and source. Filter by
  category or country, include projections, toggle reference years, export CSV.
- **A country page → All data** — defaults to one row per **year** with every indicator
  across it; switch to **By indicator** for the long form where each row is a single
  value with its status and source. Both export CSV.

In both, **click any column heading to sort** by it. Columns follow the spreadsheet's own
sequence (via `indicators.display_order`), not the category tree. Clicking the same
heading again reverses the direction; numeric columns start largest-first, text columns
A→Z, and missing values always sink to the bottom so a descending sort does not fill the
top of the table with blanks.

### TLS on Windows

Some Windows PHP builds ship without a CA bundle, so outbound HTTPS fails with
`cURL error 60`. The application looks for a bundle automatically (including the one
that ships with Git for Windows) and passes it to the HTTP client; override the
location with `DEMOGRAPHICS_CA_BUNDLE`. Verification is never disabled.

To fix it globally instead, set `curl.cainfo` and `openssl.cafile` in `php.ini`.

## 5. Scheduling

```bash
php artisan schedule:work     # or a single cron entry running schedule:run
php artisan queue:work        # processes queued fetches
```

The scheduler looks hourly for datasets whose `next_run_at` has passed, so each source
runs on its own declared cadence.

## 6. Frontend

```bash
cd frontend
npm install
npm run dev                   # http://localhost:5173, proxies /api to :8000
```

The dev server binds to IPv6 `localhost`; use `http://localhost:5173` rather than
`127.0.0.1:5173`.

The **Table** page switches between the four modules and starts with every indicator as a
column. The full set is wide enough that a country's row is hard to follow — 42 columns for
demographics, 77 for a country's whole history — so there are two ways to narrow it:

- **Columns** opens a panel listing every indicator grouped by category, with a per-category
  select-all. Untick one to hide it.
- **× on any column heading** hides that column directly, where you are already looking.

The button always states how many columns are showing (`Columns (41/42)`) and how many are
hidden, with a one-click **Show all**. The selection is remembered per module across
reloads.

A column can be sorted by clicking its heading; missing values always sort to the bottom
regardless of direction. Each country page carries a **Current vital statistics** table
comparing its latest year with the year before.

## 7. Tests

```bash
cd backend  && php artisan test     # 135 tests
cd frontend && npm test             # 37 tests
```

Backend tests run against PostgreSQL (`demographics_economy_test`, configured in
`phpunit.xml`) because the schema depends on Postgres behaviour.

## 8. Administration

Admin endpoints use Sanctum tokens. A user is seeded from `ADMIN_EMAIL` /
`ADMIN_PASSWORD` (defaults `admin@example.com` / `password`).

```bash
curl -X POST http://127.0.0.1:8000/api/v1/auth/token \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"password"}'
```

Use the returned token on `/api/v1/admin/*`, or sign in through the Admin page.
