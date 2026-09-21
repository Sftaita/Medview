import { Route, Routes } from 'react-router-dom'
import './App.css'
import { AppShell } from './components/AppShell'
import { AuthLayout } from './components/AuthLayout'
import { ProtectedRoute } from './features/auth/ProtectedRoute'
import { PublicOnlyRoute } from './features/auth/PublicOnlyRoute'
import { MyAvailabilityProvider } from './features/availability/MyAvailabilityProvider'
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

function App() {
  return (
    <Routes>
      {/* Public pages: brand panel + form, no navigation. */}
      <Route element={<AuthLayout />}>
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
      </Route>

      {/* Authenticated pages: sidebar (desktop) / bottom navigation (phone). */}
      <Route
        element={
          <ProtectedRoute>
            {/* Above the routes: the dashboard and the calendar share one optimistic, autosaving store. */}
            <MyAvailabilityProvider>
              <AppShell />
            </MyAvailabilityProvider>
          </ProtectedRoute>
        }
      >
        <Route path="/" element={<DashboardPage />} />
        <Route path="/account" element={<AccountPage />} />
        <Route path="/my-availability" element={<MyAvailabilityPage />} />
        <Route path="/my-duties" element={<MyDutiesPage />} />
        <Route path="/plannings" element={<PlanningsPage />} />
        <Route path="/plannings/:planningId" element={<PlanningDetailPage />} />
        <Route path="/planning-periods/:planningPeriodId" element={<PlanningPeriodPage />} />
        <Route path="/availability-campaigns/:campaignId" element={<AvailabilityCampaignPage />} />
      </Route>
    </Routes>
  )
}

export default App
