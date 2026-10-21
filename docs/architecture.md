# Architecture

## What this is

A store for country-level demographic indicators where **every value can be traced to
the bytes it was read from**, history is never rewritten, and missing data is recorded
rather than guessed.

The tables are module-agnostic (`indicators.module` is `demographics` today and will be
`economy`, `debt` and `wealth` later), so the other three modules described in the brief
slot into the same structures without migration.

## Layers

```
Source (publisher, credibility rank)
  └── SourceDataset (one endpoint or file, with its own cadence and adapter)
        └── SourceFetchRun      one attempt: status, counts, error, duration
        └── SourceSnapshot      the exact payload, content-addressed and gzipped
              └── Observation   one value, for one entity, indicator and period
```

Supporting tables: `entities`, `entity_name_aliases`, `indicators`,
`indicator_categories`, `csv_column_mappings`, `ingestion_batches`,
`ingestion_quarantine`, `national_statistics_offices`, `observation_supersessions`,
`observation_conflicts`.

## The observation is immutable

An `observations` row is a fact: *this source said this number, for this country and
indicator, for this period, at this time*. It is never updated.

When a source publishes a different number for the same series point:

1. a new row is inserted with `revision = previous + 1`;
2. a row is written to `observation_supersessions` linking old → new;
3. the old row stays exactly as it was.

An observation is *current* when no row in `observation_supersessions` names it as
superseded. `Observation::scopeCurrent()` expresses that, and every read uses it. There
is deliberately no SQL view for this: a view built with `SELECT o.*` freezes its column
list at creation time, so adding a column to `observations` silently made the view wrong
until it was rebuilt. Because only a *pointer* table changes, a full audit of what was
known and when is always possible.

Values that were *calculated* rather than reported carry `is_derived`. They come from a
single low-ranked source (`derived_calculations`) and are only written where no source has
published the figure for that indicator and period, so a calculation can never displace a
published value. See `demographics:derive`.

Re-ingesting identical data is a no-op: a SHA-256 `content_hash` over
(entity, indicator, period, source, status, value) is unique, so the second run reports
`unchanged` and writes nothing. This is what makes frequent polling cheap.

## Source priority and conflict retention

Sources carry a `credibility_rank`:

| Rank | Source type |
|---|---|
| 1 | National statistical authority |
| 2 | United Nations |
| 3 | Other international official (World Bank, IMF, OECD) |
| 4 | Wikipedia |
| 5 | Manual / unverified |
| 6 | Other |

When two sources hold the same series point and the values differ, `ConflictDetector`
writes an `observation_conflicts` row recording the delta, the percentage difference and
a significance band (minor < 1%, notable < 5%, major ≥ 5%). Both observations stay. The
API serves the higher-ranked one and returns the other as `other_sources` with an
`agrees` flag, so disagreement is visible in the UI rather than resolved silently.

Conflicts are only searched for where a second source actually exists for that series,
which keeps a 150,000-row backfill from doing a lookup per row.

## Missing data

Three distinct policies, chosen so that nothing is ever invented:

- **A cell the source leaves blank** in a table it otherwise populates becomes an
  `observations` row with `status = missing`, `value = NULL` and a `missing_reason`
  such as `not_collected`. It is excluded from charts and shown as `— (reason)`.
- **A cell declaring absence** (`-`, `N/A`) becomes a missing row with `not_published`.
- **Years a bulk API simply omits** are *not* written as placeholder rows; they are
  reported by `GET /api/v1/coverage` as the span actually held. Writing 150,000 nulls to
  express "the World Bank has no 1961 figure for Tuvalu" would bury the real absences.

Values are never interpolated, and no figure is labelled with a reference period the
source did not state — such rows are quarantined instead.

## Undated values

Some sources state a figure without stating which year it belongs to — several of the
spreadsheet's tables have a `Year` column that is mostly blank, and `childlessness` has
none at all. Those values are kept rather than discarded: they are dated to the
**compilation year of the document** and flagged `period_is_assumed`, which the API
returns and the UI renders as "period assumed".

The flag is what makes this honest. A reader can always tell an undated figure from a
dated one, and can correct the period through curation.

## Curation

A person can correct any stored value from the country page. A correction is **not** an
update: it inserts a new observation marked `is_curated`, and reads serve it ahead of
every ingested source because a human looking at the actual statistical yearbook
outranks an automated ingest.

The source values it competes with stay current, so the UI still shows what was
disagreed with ("corrected from the World Bank's 340,003,797"). Repeated corrections of
the same period supersede the earlier curation, never the sources.

## Discovering sources

When a scrape finds nothing usable for a country, a web search runs and the results are
stored in `source_candidates` for review. **Nothing found by search is recorded as
data.** Accepting a candidate records a judgement about a URL; it does not create
observations. This is deliberate: "search the internet for it" is a discovery step, and
treating whatever a search engine surfaces as authoritative would quietly undo the
source ranking.

## Estimates, projections and observations

`status` (`reported`, `preliminary`, `revised`, `estimated`, `projected`, `modelled`,
`missing`, `not_applicable`) plus the `is_estimate` / `is_projection` flags keep these
categories apart. The UN WPP adapter marks everything after the last estimate year as
`projected`; charts draw projections as a separate dashed series so a modelled figure is
never read as an observation.

## Sub-annual data

`indicators.supports_disaggregation` defaults to `false`. The writer refuses any
quarterly or monthly observation for an indicator that is not declared to be published
at that frequency, records the refusal in `ingestion_quarantine`, and counts it as
rejected. Annual series are therefore never divided or interpolated into quarterly ones.

The schema supports `quarterly`, `monthly` and `point` periods with a canonical
`period_key` (`2024`, `2024Q3`, `2024-07`), so a source that genuinely publishes monthly
figures needs no schema change — only an adapter and the flag.

## Ingestion

```
SourceAdapter::fetch()      →  retrieve, snapshot the payload verbatim
SourceAdapter::normalize()  →  yield ObservationDraft objects
IngestionPipeline::run()    →  batch, write, report
ObservationWriter::write()  →  dedupe, version, conflict-check, insert
```

Fetch and normalise are separate so a payload can be re-parsed after a parser fix
without hitting the network again. `SnapshotStore` is content-addressed, so an unchanged
payload is never stored twice and a changed one sits beside the old one.

Every attempt leaves a `source_fetch_runs` row, including failures, with the HTTP status,
byte count, record counts and error — so "when was this last tried and what happened" is
always answerable.

## The spreadsheet importer

`Demographics(Demographic).csv` is not one table. It is ten tables sharing a sheet, and
several start with a column literally named `Country`. `CsvTableSplitter` locates each
sub-table by scanning the header left to right for its known first column, advancing a
cursor, so the *n*-th `Country` header maps to the *n*-th sub-table in the definition
order.

Two details matter:

- The right-hand tables are **independent ranked lists that flow down the rows**. The
  row's country is not the subject of a right-hand table entry; the name in that table's
  own key column is. `csv_column_mappings` therefore gives each sub-table an `entity`
  role column of its own.
- Column labels must be compared without stripping parentheticals, because `By year` and
  `By Year (Immigrants)` are different columns. `EntityNameNormalizer::columnKey` is used
  for headers; `normalize` (which does strip them) is used for entity names.

`ValueParser` is deliberately conservative: thousands separators (including Indian-style
grouping), footnote asterisks, `est.` qualifiers, embedded reference years (`0.93/2025`)
and open-ended comparisons (`<2008`) are read; ranges (`55-74`), spreadsheet error tokens
(`#REF!`) and prose are refused and quarantined rather than coerced into a number.

Malformed rows are collapsed by (sub-table, reason, offending value) so a systematic
problem produces one reviewable row with a count instead of hundreds.

## Reference-period rules

| Situation | Period used |
|---|---|
| Value column has a paired year column | that year |
| Value embeds its year (`0.93/2025`) | the embedded year |
| Source fixes the year in the header (`Country[2019]`) | that year |
| Indicator whose value *is* a year | the value itself |
| Otherwise | the row's snapshot year, taken from the source's own year columns |
| No period can be established | quarantined, never dated by assumption |

## Matrix views

Two table shapes exist on top of the same observations, because two questions get asked
constantly:

- **`GET /api/v1/matrix`** — countries as rows, indicators as columns, each cell the
  country's **latest known value**. This is the shape of the original spreadsheet, with
  every cell carrying the period and source it came from. Only countries and territories
  are included; the World/continent aggregate rows are excluded unless
  `include_aggregates=1`, so a total is never mixed into a list of countries.

  Columns follow the **spreadsheet's own sequence** — peak population, present
  population, decline, migration, fertility, births, deaths, age, life expectancy, per
  capita — via `indicators.display_order`, not the category tree. Indicators that are not
  columns in that sheet (annual births and deaths, natural change, household and social
  measures) follow afterwards. `Continent` is shown next to the country name, as in the
  sheet.
- **`GET /api/v1/entities/{key}/matrix`** — one country, **one row per year**, every
  indicator across. This is how a statistical yearbook is laid out.

"Latest known" means the most recent period a source actually published a figure for.
Periods recorded as *missing* are skipped when choosing the cell, so a country does not
go blank merely because its newest year is unknown; the year it did come from is always
reported with the value. In the year view those absences are kept as rows, so a year with
no data says so rather than disappearing.

Within a period, the served value follows the same rule everywhere: curated first, then
source credibility rank.

## API

Public read endpoints under `/api/v1`: `entities`, `entities/{key}/observations` (the flat
"all data" view for one country), `entities/{key}/matrix` (one row per year), `matrix`
(countries as rows, latest value per indicator), `indicators`, `series`, `series/latest`,
`compare`, `observations/{uuid}`, `observations/{uuid}/provenance`,
`observations/{uuid}/history`, `conflicts`, `coverage`, `sources`.

Every value response carries `value`, `unit`, `status`, `is_estimate`, `is_projection`,
`is_missing`, `period_is_assumed`, `is_curated`, `missing_reason`, `raw_value`,
`reference_note`, `retrieved_at`, a `source` block with its credibility rank, and a
`provenance_url`.

Admin endpoints under `/api/v1/admin` (Sanctum): trigger a dataset ingestion, upload a
replacement spreadsheet, scrape one country (`entities/{entity}/fetch`), review source
candidates, correct an observation, and read batches, fetch runs, the quarantine queue
and conflict resolutions.

## Deliberate limitations

- **National statistical offices are a registry, not yet an ingestion path.** 61 offices
  are seeded with their official domains so the priority ranking is real; wiring them up
  is per-country adapter work (see `docs/source-registry.md`).
- **Wikipedia coverage is population only.** The infobox is the reliable, structured part
  of an article; other figures would need prose extraction that is not worth the error
  rate for a rank-4 source.
- **Conflict significance uses percentage bands only.** A 0.03% difference on a
  population figure is recorded as `minor` even though it is 100,000 people.
