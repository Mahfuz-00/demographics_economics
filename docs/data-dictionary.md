# Data dictionary

## Entities

| `entity_type` | Meaning | Count |
|---|---|---|
| `country` | Sovereign state | 194 |
| `territory` / `dependency` | Dependent territory; `sovereignty_entity_id` points at the administering state | 57 |
| `region` | Continent used by the source spreadsheet (Africa, Asia, Europe, North America, South America, Oceania) | 6 |
| `aggregate` | World total | 1 |
| `group` | Groupings that exist in the source but are not one country (European Union, Channel Islands) | 2 |

Total 259 entities: ISO 3166-1 in full, plus Kosovo and Akrotiri and Dhekelia (which the
source names but ISO does not assign), plus the region rows.

Two additions carry no ISO code on purpose — Kosovo (`XKX`, user-assigned) and Akrotiri
and Dhekelia — because inventing an official code would be worse than recording that
there isn't one.

`csv_label` preserves the label the source used, e.g. India is `Asia(South)`, Russia is
`Europe(East)`.

## Indicator conventions

| Field | Meaning |
|---|---|
| `unit` | `persons`, `percent`, `per_1000`, `years`, `children_per_woman`, `currency_usd`, `currency_usd_bn`, `count`, `year`, `ratio` |
| `value_kind` | `level`, `rate`, `ratio`, `index`, `count`, `currency`, `year` — drives display precision |
| `supports_disaggregation` | whether the indicator is published sub-annually. **All indicators here are `false`** |
| `is_stock` | a stock measured at a point in time rather than a flow over a period |
| `is_sensitive` | flagged as sensitive or unverified |

## Population

| Code | Name | Unit | Notes |
|---|---|---|---|
| `pop.total` | Present population | persons | Annual from the World Bank since 1960; the spreadsheet's snapshot value is retained beside it |
| `pop.change.annual` | Annual change in population | persons | From vital-statistics tables |
| `pop.natural_change.rate` | Rate of natural change | per 1,000 | Births less deaths per 1,000 population |
| `pop.natural_change.absolute` | Natural change | persons | Births less deaths, absolute |
| `pop.peak` | Peak population | persons | Dated to the year the peak occurred, not the snapshot year |
| `pop.decline.absolute` | Decline from peak | persons | |
| `pop.decline.percent` | Decline from peak (%) | percent | |
| `pop.trend.annual` | Current annual change | persons | |
| `pop.years.declining` | Years of population decline | count | |
| `pop.natural_decline.since_1970s` | Cumulative natural decline since the 1970s | persons | Cumulative, so not comparable with annual figures |
| `pop.natural_decline.start_year` | Year natural decline began | year | |
| `pop.native_share` | Native / ethnic-majority share | percent | Definition varies by country; the source does not define it |
| `pop.diaspora` | Diaspora population | persons | |
| `pop.median_age` | Median age | years | |
| `pop.age_65plus_share` | Population aged 65+ | percent | |
| `pop.growth_rate` | Population change | percent | The spreadsheet's `Change` column holds percentage points for some rows and absolute millions for others; the resulting conflicts are recorded rather than resolved |
| `pop.sex_workers.reported` | Reported people in sex work | persons | **Sensitive**, unverified, definitionally inconsistent across countries |

## Fertility and births

| Code | Name | Unit | Notes |
|---|---|---|---|
| `fert.tfr` | Total fertility rate | children/woman | |
| `fert.tfr.minimum` | Lowest recorded total fertility rate | children/woman | Dated to the year it occurred |
| `fert.tfr.sub_replacement_start_year` | Year fertility fell below replacement | year | The source's `YWFRDBRL` column |
| `fert.childlessness` | Childlessness | percent | |
| `birth.rate.crude` | Crude birth rate | per 1,000 | |
| `birth.count.annual` | Live births per year | persons | From vital-statistics tables — a count, not a rate |
| `birth.nonmarital_share` | Births outside marriage | percent | |
| `birth.foreign_mother_share` | Live births to foreign mothers | percent | Source reference year 2019 |

## Mortality

| Code | Name | Unit |
|---|---|---|
| `death.rate.crude` | Crude death rate | per 1,000 |
| `death.count.annual` | Deaths per year | persons |
| `death.infant_rate` | Infant mortality rate | per 1,000 |
| `death.life_expectancy` | Life expectancy at birth | years |

## Migration

| Code | Name | Unit | Notes |
|---|---|---|---|
| `mig.immigrant_stock` | Immigrant stock | persons | Stock |
| `mig.foreign_born_share` | Immigrant share of population | percent | |
| `mig.emigrant_stock` | Emigrant stock | persons | Stock |
| `mig.net_stock` | Net migrant stock | persons | Stock |
| `mig.net_annual` | Net migration | persons | Annual flow, from the World Bank |
| `mig.rate.crude` | Crude migration rate | per 1,000 | Net migration per 1,000 population |

## Households

| Code | Name | Unit | Notes |
|---|---|---|---|
| `household.divorce_rate` | Divorce rate | percent | The source labels the column a percentage without defining the denominator. Treated as reported; not comparable across countries without care |
| `household.single_parent_share` | Single-parent households | percent | |

## Society and economy

| Code | Name | Unit | Module |
|---|---|---|---|
| `social.pension_exhaustion_year` | Projected pension fund exhaustion year | year | demographics |
| `economy.gdp.per_capita.nominal` | GDP per capita (nominal) | currency_usd | economy |
| `economy.entertainment_value` | Entertainment industry value | currency_usd_bn | economy |

The two `economy.*` indicators are carried by the source spreadsheet and tagged with the
economy module so they land in the right place when that module is built. They key on an
industry category rather than a country, so they are quarantined rather than attached to
a country.

## How the spreadsheet maps to indicators

`csv_column_mappings` is the machine-readable version of this table and drives the
import.

| Sub-table | Key column | Indicator | Period from |
|---|---|---|---|
| `core` | `Country` | 27 indicators | paired year columns, else the row's snapshot year |
| `nonmarital_birth` | `Country` | `birth.nonmarital_share` | `Year` |
| `sex_workers` | `Country` | `pop.sex_workers.reported` | `Year` |
| `divorce` | `Country` | `household.divorce_rate` | `Year` |
| `single_parent` | `Country` | `household.single_parent_share` | `Year` |
| `foreign_mother_births` | `Country[2019]` | `birth.foreign_mother_share` | fixed: 2019 |
| `entertainment` | `Entertaintment Industry Value` | `economy.entertainment_value` | `Year` |
| `pension` | `Country` | `social.pension_exhaustion_year` | the value itself |
| `childlessness` | `Childlessness` | `fert.childlessness` | none stated → quarantined |
| `native_share` | `Country` | `pop.native_share` | `Year` |

## Interpreting a value

Read a value together with four things, all of which the API returns:

1. **`status`** — `reported`, `preliminary`, `estimated` or `projected` are not
   interchangeable. A projection must never be compared directly with an observation.
2. **`period_is_assumed`** — the source stated this figure without stating a year, so it
   is dated to the compilation year of the document. Treat the period as unknown-but-
   bounded, not as fact.
3. **`is_curated`** — a person reviewed and corrected this value; it outranks every
   ingested source for that period.
4. **`reference_note`** and **`raw_value`** — what the source actually wrote, including
   footnote qualifiers.
5. **`source`** and its `credibility_rank` — who is asserting the number.
6. **`has_conflict` / `other_sources`** — whether anyone disagrees.

`GET /api/v1/observations/{uuid}/provenance` returns all of it plus the payload snapshot.
