import { Route, Routes } from 'react-router-dom'
import './App.css'
import { AppShell } from './components/AppShell'
import { AuthLayout } from './components/AuthLayout'
import { ProtectedRoute } from './features/auth/ProtectedRoute'
import { PublicOnlyRoute } from './features/auth/PublicOnlyRoute'
import { GuestHomeGate } from './features/home/GuestHomeGate'
import { MyAvailabilityProvider } from './features/availability/MyAvailabilityProvider'
import { AccountPage } from './pages/AccountPage'
import { AvailabilityCampaignPage } from './pages/AvailabilityCampaignPage'
import { DashboardPage } from './pages/DashboardPage'
import { ForgotPasswordPage } from './pages/ForgotPasswordPage'
import { InvitationPage } from './pages/InvitationPage'
import { LoginPage } from './pages/LoginPage'
import { MyAvailabilityPage } from './pages/MyAvailabilityPage'
import { MyDutiesPage } from './pages/MyDutiesPage'
import { PlanningDetailPage } from './pages/PlanningDetailPage'
import { PlanningPeriodPage } from './pages/PlanningPeriodPage'
import { PlanningsPage } from './pages/PlanningsPage'
import { RegisterPage } from './pages/RegisterPage'
import { ResetPasswordPage } from './pages/ResetPasswordPage'

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
        <Route
          path="/forgot-password"
          element={
            <PublicOnlyRoute>
              <ForgotPasswordPage />
            </PublicOnlyRoute>
          }
        />

        {/* Public and not PublicOnly: an invitee with an existing account may
            already be logged in, and someone opening a reset-password email
            may still have an old session in this browser — the link must
            keep working either way (docs/authentication.md §16-17). */}
        <Route path="/invitations/:token" element={<InvitationPage />} />
        <Route path="/reset-password" element={<ResetPasswordPage />} />
      </Route>

      {/* Authenticated pages: sidebar (desktop) / bottom navigation (phone).
          An anonymous visitor on "/" gets the public homepage instead of /login. */}
      <Route
        element={
          <GuestHomeGate>
            <ProtectedRoute>
              {/* Above the routes: the dashboard and the calendar share one optimistic, autosaving store. */}
              <MyAvailabilityProvider>
                <AppShell />
              </MyAvailabilityProvider>
            </ProtectedRoute>
          </GuestHomeGate>
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
