import { Route, Routes } from 'react-router-dom'
import './App.css'
import { AppShell } from './components/AppShell'
import { AuthLayout } from './components/AuthLayout'
import { ProtectedRoute } from './features/auth/ProtectedRoute'
import { PublicOnlyRoute } from './features/auth/PublicOnlyRoute'
import { AdminLayout } from './features/admin/AdminLayout'
import { AdminRoute } from './features/admin/AdminRoute'
import { GuestHomeGate } from './features/home/GuestHomeGate'
import { AdminActivityPage } from './pages/admin/AdminActivityPage'
import { AdminInfrastructurePage } from './pages/admin/AdminInfrastructurePage'
import { AdminOverviewPage } from './pages/admin/AdminOverviewPage'
import { AdminSettingsPage } from './pages/admin/AdminSettingsPage'
import { AdminStatisticsPage } from './pages/admin/AdminStatisticsPage'
import { AdminUserDetailPage } from './pages/admin/AdminUserDetailPage'
import { AdminUsersPage } from './pages/admin/AdminUsersPage'
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
import { SwapsPage } from './pages/SwapsPage'

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
        <Route path="/swaps" element={<SwapsPage />} />
        <Route path="/plannings" element={<PlanningsPage />} />
        <Route path="/plannings/:planningId" element={<PlanningDetailPage />} />
        <Route path="/planning-periods/:planningPeriodId" element={<PlanningPeriodPage />} />
        <Route path="/availability-campaigns/:campaignId" element={<AvailabilityCampaignPage />} />
      </Route>

      {/* Platform administration (docs/admin.md): its own frame and navigation, platform admins only. The
          guard is a convenience — every /api/admin endpoint enforces ROLE_PLATFORM_ADMIN itself (D174). */}
      <Route
        path="/admin"
        element={
          <ProtectedRoute>
            <AdminRoute>
              <AdminLayout />
            </AdminRoute>
          </ProtectedRoute>
        }
      >
        <Route index element={<AdminOverviewPage />} />
        <Route path="users" element={<AdminUsersPage />} />
        <Route path="users/:stableId" element={<AdminUserDetailPage />} />
        <Route path="statistics" element={<AdminStatisticsPage />} />
        <Route path="activity" element={<AdminActivityPage />} />
        <Route path="infrastructure" element={<AdminInfrastructurePage />} />
        <Route path="settings" element={<AdminSettingsPage />} />
      </Route>
    </Routes>
  )
}

export default App
