import { Outlet } from 'react-router-dom'
import { HealthStatus } from '../features/system/HealthStatus'
import { Icon, type IconName } from './Icon'
import { Logo } from './Logo'

const ARGUMENTS: { icon: IconName; title: string; text: string }[] = [
  {
    icon: 'calendarX',
    title: 'Un calendrier personnel',
    text: 'Déclarez vos indisponibilités une seule fois : elles valent pour toutes vos équipes.',
  },
  {
    icon: 'layers',
    title: 'Des plannings par équipe',
    text: 'Chaque période de garde garde son historique, même quand les équipes changent.',
  },
  {
    icon: 'mail',
    title: 'Une invitation, un lien',
    text: "Rejoignez une équipe depuis l'e-mail reçu, sans formulaire supplémentaire.",
  },
]

/**
 * Frame of the public pages (login, sign-up, invitation): a green brand panel
 * beside the form on desktop, the form alone on a phone.
 */
export function AuthLayout() {
  return (
    <div className="auth">
      <aside className="auth__side">
        <div className="auth__brand">
          <Logo size={44} inverse />
          <span className="auth__brand-name">MedVue</span>
        </div>
        <div className="auth__pitch">
          <p className="auth__headline">Vos gardes, vos plannings, vos indisponibilités.</p>
          <ul className="list auth__arguments">
            {ARGUMENTS.map((argument) => (
              <li key={argument.title}>
                <span className="auth__argument-icon">
                  <Icon name={argument.icon} size={22} strokeWidth={1.9} />
                </span>
                <span>
                  <strong>{argument.title}</strong>
                  <span className="auth__argument-text">{argument.text}</span>
                </span>
              </li>
            ))}
          </ul>
        </div>
        <HealthStatus inverse />
      </aside>

      <main className="auth__main">
        <div className="auth__panel">
          <div className="auth__mobile-brand">
            <Logo size={44} />
            <span className="auth__brand-name">MedVue</span>
          </div>
          <Outlet />
          <div className="auth__mobile-health">
            <HealthStatus />
          </div>
        </div>
      </main>
    </div>
  )
}
