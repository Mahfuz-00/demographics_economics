export type ModuleCode = 'demographics' | 'economy' | 'debt' | 'wealth'

/**
 * The four modules, in the order they should be offered. Each has its own spreadsheet and
 * its own set of indicators; they share one entity and observation store.
 */
export const MODULES: { code: ModuleCode; name: string; blurb: string }[] = [
  { code: 'demographics', name: 'Demographics', blurb: 'Population, births, deaths, migration and households.' },
  { code: 'economy', name: 'Economy', blurb: 'GDP, PPP, per-capita output, saving, informal economy and tourism.' },
  { code: 'debt', name: 'Debt', blurb: 'External, public, household and corporate debt as shares of GDP.' },
  { code: 'wealth', name: 'Wealth Indicator', blurb: 'National wealth, reserves, sovereign funds, external position and FDI.' },
]

export function moduleName(code: string): string {
  return MODULES.find((entry) => entry.code === code)?.name ?? code
}
