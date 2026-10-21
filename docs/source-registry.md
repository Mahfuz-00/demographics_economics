# Source registry

## Registered sources

| Code | Publisher | Rank | Datasets | Adapter | Cadence |
|---|---|---|---|---|---|
| `un_wpp` | UN DESA Population Division | 2 | `un_wpp_population` | `un_wpp` | annual |
| `world_bank` | World Bank | 3 | `world_bank_wdi_demographics` | `world_bank` | weekly |
| `wikipedia` | Wikipedia contributors | 4 | `wikipedia_country_infobox` | `wikipedia` | monthly |
| `manual_csv_v1` | Manually maintained project spreadsheet | 5 | `manual_demographic_csv` | — (manual upload) | adhoc |

Rank is not decoration: `ConflictDetector` orders by it, so a national statistics office
(rank 1) outranks the UN, which outranks the World Bank, which outranks Wikipedia, which
outranks the spreadsheet.

## What each adapter does

**`world_bank`** — one request per indicator for every country at once
(`/country/all/indicator/{code}`), ten indicators, paginated. Annual, 1960 to present,
about 140,000 observations. Deep links point at the World Bank's own page for each series
and location. Years the Bank omits are reported as coverage gaps, not written as nulls.

**`un_wpp`** — two routes, in order of preference:

1. the UN Data Portal API when `UN_DATAPORTAL_API_KEY` is set, with indicator IDs
   supplied through the dataset's `config.api.indicators` (the portal's IDs are not
   derivable and are not guessed);
2. a bulk WPP CSV downloaded by hand into `storage/app/ingestion/un_wpp/`, with the
   column mapping in `config.csv` (column names differ between WPP releases).

Without one of those the run fails loudly with instructions. Years up to
`last_estimate_year` are `estimated`; later years are `projected` and flagged.

**`wikipedia_vital_statistics`** — scrapes the vital-statistics tables from each
country's **"Demographics of <country>"** article: mid-year population, live births,
deaths, annual change, crude birth and death rates, natural change (count and rate),
migration rate, total fertility, infant mortality and life expectancy, usually by year
from the 1950s. This is the richest per-country history Wikipedia holds.

Columns are matched by **header text**, never position, because the tables differ
between articles. Two details the scraper has to handle in practice:

- many articles leave the year column header **blank** — the parser falls back to an
  unlabelled leading column whose values parse as years;
- articles often carry two tables (UN estimates and a national series) — only the most
  complete is kept, since writing both would have each overwrite the other per year.

Sex-split columns (life expectancy male/female) are skipped rather than collapsed onto
one indicator. Values for years that have not happened yet are stored as `projected`.

**`wikipedia`** — the MediaWiki parse API for each country article, reading the infobox's
`population_estimate` / `population_census` and their year fields. Article revision ID,
article URL and the citation field are all stored. Used only for recent published figures
and to surface the citation trail; it never displaces national or UN data.

**`manual_csv_v1`** — no adapter. Four hand-maintained spreadsheets are registered against
it, one per module:

| Dataset | File | Module |
| --- | --- | --- |
| `manual_demographic_csv` | `Demographics(Demographic).csv` | Demographics |
| `manual_economy_csv` | `Demographics(Economy).csv` | Economy |
| `manual_debt_csv` | `Demographics(Debt).csv` | Debt |
| `manual_indicator_csv` | `Demographics(Indicator).csv` | Wealth Indicator |

Import them with `php artisan demographics:import-csv --all` (or `--dataset=<code>` for
one), or by uploading a replacement through the admin API. Each spreadsheet has a layout
file under `database/seed-data/*_csv_layout.php` that says what every column means,
including which columns are *not* country values; the importer, the API and the UI all
read that one definition. Importing the same file again is a no-op.

**`derived_calculations`** — no adapter, and not a publisher. Holds values this module
calculated from other observations with the demographic accounting identity: crude birth
and death rates, natural change, annual population change, net migration, the natural
share of population change, and the year-on-year percentage changes. Written by
`php artisan demographics:derive`, flagged `is_derived`, and ranked below every source so a
published figure always wins.

## List pages (Economy, Debt, Wealth)

Demographics is published one article per country — "Demographics of France". Economy,
Debt and Wealth are not: they are published as a **single ranking page per topic**, and
`wikipedia_list` reads those.

Only the columns that map onto indicators this store already holds are read. Everything
else on the page is left alone — the point is not to copy the table, it is to fill
indicators that exist.

| Page | Column read | Indicator |
| --- | --- | --- |
| GDP (nominal) | IMF (2026) | `economy.gdp.nominal_bn` |
| GDP (nominal) per capita | IMF | `economy.gdp.per_capita.nominal` |
| External debt | Total debt (billions) | `debt.external_bn_usd` |
| External debt | % of GDP | `debt.external_pct_gdp` |
| Government debt | General government gross debt (2025) | `debt.public_pct_gdp` |
| Corporate debt | 2023 | `debt.corporate_pct_gdp` |

Rows landed: 192 countries of GDP, 209 of external debt, 188 of public debt, 57 of
corporate debt. Each column's values are checked for variety as well as range — a column
read out of alignment shows up as many countries sharing one figure, which is how the
faults below were caught.

**GDP (nominal)** publishes one column per body — `IMF (2026)`, `World Bank (2025)`,
`United Nations (2024)`. Only the **IMF** column is read, so a country carries one figure
rather than three vintages of the same measure competing in the table. The values are in
**millions**; read as billions, US GDP would have been stored as 30,337,162bn.

Pages registered but **not read**, each with its reason stated in the dataset config. A
page that cannot be read correctly is not read at all — no request is made:

- **Household debt** — one column per year is the right shape, but the table yields a value
  for only 3 of its rows, so its data cells do not line up with its header row.
- **Total private wealth** — countries are grouped under region cells that span several
  rows, and a subtotal is read back into every country beneath it. 123 countries came out
  with one of only 3 distinct figures: the world and regional totals.
- **Foreign exchange reserves** — the reserve columns are multi-level: a `colspan` group
  covers total, including-gold and excluding-gold, which cannot be told apart without
  reading the colspan structure.
- **Sovereign wealth funds** — only 1 row maps; the page lists *funds* per country rather
  than one fund per country, which reads as a ragged table.

Anything a page offers that is not configured is reported as *unmatched* rather than
matched approximately. The failure worth designing against is a plausible-looking wrong
number, not an empty one.

## National statistical offices

`national_statistics_offices` holds 61 offices with their official domains, registered so
that rank 1 is real rather than theoretical. They are **not** wired for automated
ingestion: national portals mostly require per-country work, and scraping them badly would
put unreliable data behind the highest credibility rank.

Each row carries `has_api`, `data_portal_url` and `access_notes`, and the seeded set
records that API availability still needs verifying per country. The registry is meant to
be extended from the admin API as countries are onboarded.

## Adding a source

1. **Register it.** Insert a `sources` row with a `credibility_rank`, and a
   `source_datasets` row with `access_method`, `update_frequency` and an `adapter_key`.

2. **Write the adapter** in `app/Domain/Demographics/Ingestion/Adapters/`, implementing
   `SourceAdapter`:

   ```php
   public function fetch(SourceDataset $dataset): RawDataset
   {
       // Retrieve bytes, hand them to SnapshotStore, return the snapshot plus
       // whatever in-memory handle normalize() needs.
   }

   public function normalize(RawDataset $raw): iterable
   {
       // yield ObservationDraft objects
   }
   ```

   Use `App\Support\HttpClient::make()` so timeouts, retries and the CA bundle are
   applied consistently. Yield drafts lazily — the writer streams them.

3. **Register the key** in `AdapterRegistry::$adapters`.

4. **Test it** against a recorded fixture. The pipeline separates fetch from normalize
   precisely so normalisation can be tested without network access:

   ```php
   $raw = new RawDataset($dataset, $snapshot, payload: json_decode($fixture, true));
   $drafts = iterator_to_array($adapter->normalize($raw));
   ```

5. **Schedule it.** No cron entry is needed: the hourly dispatcher picks up any enabled
   dataset whose `next_run_at` has passed and computes the next one from
   `update_frequency`.

### Rules an adapter must follow

- **Never fabricate.** No interpolation, no derived sub-annual figures, no default
  reference period.
- **Preserve the original text** in `rawValue`, and set `status`, `is_estimate` and
  `is_projection` honestly.
- **Set `sourceUrl`** to a deep link a human can check, and `sourceRecordRef` to
  something that identifies the record inside the payload.
- **Do not resurrect aggregates.** Skip series for supranational codes the module does
  not model; the platform carries its own region entities.
- **Respect the licence and rate limits**, including a descriptive User-Agent for
  Wikimedia.

## Documenting disagreement

When sources disagree, do not "fix" either number. `ConflictDetector` records the pair;
`observation_conflicts.resolution_status` and `resolution_note` capture a human decision.
A resolution records a judgement — it never deletes the losing value.

Current state after a full ingest of the World Bank and the spreadsheet: 1,723 recorded
conflicts, overwhelmingly between the World Bank and the hand-maintained sheet. One
example worth knowing about: the spreadsheet's `Change` column holds percentage points
for most countries but absolute millions for India, so `pop.growth_rate` for India 2023 is
recorded as 12 against the World Bank's 0.88 — a `major` conflict, and a genuine defect in
the source that the module surfaces instead of propagating.
