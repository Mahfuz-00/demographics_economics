import { NavLink, Navigate, Route, Routes } from 'react-router-dom'

import { AdminPage } from './pages/AdminPage'
import { AnalysisPage } from './pages/AnalysisPage'
import { ComparePage } from './pages/ComparePage'
import { CountriesPage } from './pages/CountriesPage'
import { CountryProfilePage } from './pages/CountryProfilePage'
import { DashboardPage } from './pages/DashboardPage'
import { IndicatorExplorerPage } from './pages/IndicatorExplorerPage'
import { IndicatorsPage } from './pages/IndicatorsPage'
import { MapPage } from './pages/MapPage'
import { MatrixPage } from './pages/MatrixPage'
import { QualityPage } from './pages/QualityPage'
import { SourcesPage } from './pages/SourcesPage'

const NAV = [
  { to: '/', label: 'Dashboard', end: true },
  { to: '/table', label: 'Table' },
  { to: '/map', label: 'Map' },
  { to: '/countries', label: 'Countries' },
  { to: '/indicators', label: 'Indicators' },
  { to: '/compare', label: 'Compare' },
  { to: '/analysis', label: 'Analysis' },
  { to: '/sources', label: 'Sources' },
  { to: '/quality', label: 'Data quality' },
  { to: '/admin', label: 'Admin' },
]

function Layout({ children }: { children: React.ReactNode }) {
  return (
    <div className="min-h-full">
      <header className="border-b border-ink-200 bg-white">
        <div className="mx-auto flex max-w-7xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
          <div className="flex items-baseline gap-2">
            <span className="text-sm font-semibold tracking-tight text-ink-900">Demographics Data</span>
            <span className="text-[11px] text-ink-500">source-traceable historical indicators</span>
          </div>
          <nav className="flex flex-wrap items-center gap-1">
            {NAV.map((item) => (
              <NavLink
                key={item.to}
                to={item.to}
                end={item.end}
                className={({ isActive }) =>
                  `rounded-md px-2.5 py-1.5 text-xs font-medium transition ${
                    isActive ? 'bg-ink-900 text-white' : 'text-ink-600 hover:bg-ink-100'
                  }`
                }
              >
                {item.label}
              </NavLink>
            ))}
          </nav>
        </div>
      </header>

      <main className="mx-auto max-w-7xl px-4 py-6">{children}</main>

      <footer className="border-t border-ink-200 px-4 py-4 text-center text-[11px] text-ink-500">
        Observations are append-only; every value links to its source, reference period and retrieval time.
      </footer>
    </div>
  )
}

export default function App() {
  return (
    <Layout>
      <Routes>
        <Route path="/" element={<DashboardPage />} />
        <Route path="/table" element={<MatrixPage />} />
        <Route path="/map" element={<MapPage />} />
        <Route path="/countries" element={<CountriesPage />} />
        <Route path="/countries/:key" element={<CountryProfilePage />} />
        <Route path="/indicators" element={<IndicatorsPage />} />
        <Route path="/indicators/:code" element={<IndicatorExplorerPage />} />
        <Route path="/compare" element={<ComparePage />} />
        <Route path="/analysis" element={<AnalysisPage />} />
        <Route path="/sources" element={<SourcesPage />} />
        <Route path="/quality" element={<QualityPage />} />
        <Route path="/admin" element={<AdminPage />} />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </Layout>
  )
}
