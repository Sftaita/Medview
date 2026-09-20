import { NavLink, Route, Routes } from 'react-router-dom'
import './App.css'
import { ProtectedRoute } from './features/auth/ProtectedRoute'
import { PublicOnlyRoute } from './features/auth/PublicOnlyRoute'
import { useAuth } from './features/auth/useAuth'
import { HealthStatus } from './features/system/HealthStatus'
import { AccountPage } from './pages/AccountPage'
import { AvailabilityCampaignPage } from './pages/AvailabilityCampaignPage'
import { DashboardPage } from './pages/DashboardPage'
import { InvitationPage } from './pages/InvitationPage'
import { LoginPage } from './pages/LoginPage'
import { MyAvailabilityPage } from './pages/MyAvailabilityPage'
import { MyDutiesPage } from './pages/MyDutiesPage'
import { PlanningDetailPage } from './pages/PlanningDetailPage'
import { PlanningPeriodPage } from './pages/PlanningPeriodPage'
import { PlanningsPage } from './pages/PlanningsPage'
import { RegisterPage } from './pages/RegisterPage'

const navItems = [
  { to: '/', label: 'Tableau de bord', end: true },
  { to: '/my-availability', label: 'Mes indisponibilités' },
  { to: '/my-duties', label: 'Mes gardes' },
  { to: '/plannings', label: 'Plannings' },
]

function AccountNav() {
  const { user, logout } = useAuth()

  if (!user) {
    return <NavLink to="/login">Se connecter</NavLink>
  }

  return (
    <>
      <NavLink to="/account">Mon compte</NavLink>
      <button type="button" onClick={logout}>
        Se déconnecter
      </button>
    </>
  )
}

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
        <AccountNav />
        <HealthStatus />
      </header>

      <main className="app-content">
        <Routes>
          <Route
            path="/login"
            element={
              <PublicOnlyRoute>
                <LoginPage />
              </PublicOnlyRoute>
            }
          />
          <Route
            path="/register"
            element={
              <PublicOnlyRoute>
                <RegisterPage />
              </PublicOnlyRoute>
            }
          />

          {/* Public and not PublicOnly: an invitee with an existing account may already be logged in. */}
          <Route path="/invitations/:token" element={<InvitationPage />} />

          <Route
            path="/"
            element={
              <ProtectedRoute>
                <DashboardPage />
              </ProtectedRoute>
            }
          />
          <Route
            path="/account"
            element={
              <ProtectedRoute>
                <AccountPage />
              </ProtectedRoute>
            }
          />
          <Route
            path="/my-availability"
            element={
              <ProtectedRoute>
                <MyAvailabilityPage />
              </ProtectedRoute>
            }
          />
          <Route
            path="/my-duties"
            element={
              <ProtectedRoute>
                <MyDutiesPage />
              </ProtectedRoute>
            }
          />
          <Route
            path="/plannings"
            element={
              <ProtectedRoute>
                <PlanningsPage />
              </ProtectedRoute>
            }
          />
          <Route
            path="/plannings/:planningId"
            element={
              <ProtectedRoute>
                <PlanningDetailPage />
              </ProtectedRoute>
            }
          />
          <Route
            path="/planning-periods/:planningPeriodId"
            element={
              <ProtectedRoute>
                <PlanningPeriodPage />
              </ProtectedRoute>
            }
          />
          <Route
            path="/availability-campaigns/:campaignId"
            element={
              <ProtectedRoute>
                <AvailabilityCampaignPage />
              </ProtectedRoute>
            }
          />
        </Routes>
      </main>
    </div>
  )
}

export default App
