import { NavLink, Route, Routes } from 'react-router-dom'
import './App.css'
import { HealthStatus } from './features/system/HealthStatus'
import { AvailabilityCampaignPage } from './pages/AvailabilityCampaignPage'
import { DashboardPage } from './pages/DashboardPage'
import { MyAvailabilityPage } from './pages/MyAvailabilityPage'
import { MyDutiesPage } from './pages/MyDutiesPage'
import { PlanningPeriodPage } from './pages/PlanningPeriodPage'
import { TeamAvailabilityCalendarPage } from './pages/TeamAvailabilityCalendarPage'
import { TeamDetailPage } from './pages/TeamDetailPage'
import { TeamsPage } from './pages/TeamsPage'

const navItems = [
  { to: '/', label: 'Tableau de bord', end: true },
  { to: '/my-availability', label: 'Mes indisponibilités' },
  { to: '/my-duties', label: 'Mes gardes' },
  { to: '/teams', label: 'Équipes' },
]

function App() {
  return (
    <div className="app-shell">
      <header className="app-header">
        <span className="app-title">MedVue</span>
        <nav>
          {navItems.map((item) => (
            <NavLink key={item.to} to={item.to} end={item.end}>
              {item.label}
            </NavLink>
          ))}
        </nav>
        <HealthStatus />
      </header>

      <main className="app-content">
        <Routes>
          <Route path="/" element={<DashboardPage />} />
          <Route path="/my-availability" element={<MyAvailabilityPage />} />
          <Route path="/my-duties" element={<MyDutiesPage />} />
          <Route path="/teams" element={<TeamsPage />} />
          <Route path="/teams/:teamId" element={<TeamDetailPage />} />
          <Route
            path="/teams/:teamId/availability-calendar"
            element={<TeamAvailabilityCalendarPage />}
          />
          <Route
            path="/planning-periods/:planningPeriodId"
            element={<PlanningPeriodPage />}
          />
          <Route
            path="/availability-campaigns/:campaignId"
            element={<AvailabilityCampaignPage />}
          />
        </Routes>
      </main>
    </div>
  )
}

export default App
