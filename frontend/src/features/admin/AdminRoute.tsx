import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth/useAuth'
import './admin.css'

/**
 * Shows the administration only to a platform administrator. A convenience, not a protection: every
 * /api/admin endpoint refuses anyone else (403) whatever the frontend shows (D174).
 */
export function AdminRoute({ children }: { children: ReactNode }) {
  const { user } = useAuth()

  if (!user?.platformAdmin) {
    return (
      <section className="page adm-denied">
        <h1>Accès réservé</h1>
        <p className="page__lead">
          Cet espace est réservé aux administrateurs de la plateforme MedVue. Les plannings et les équipes se
          gèrent depuis leur propre page.
        </p>
        <Link to="/" className="btn btn--primary">
          Retour au tableau de bord
        </Link>
      </section>
    )
  }

  return <>{children}</>
}
