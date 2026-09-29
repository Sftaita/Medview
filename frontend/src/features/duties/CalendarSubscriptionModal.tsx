import { useEffect, useRef, useState } from 'react'
import { Icon } from '../../components/Icon'
import { Overlay } from '../../components/Overlay'
import {
  disableCalendarFeed,
  enableCalendarFeed,
  fetchCalendarFeed,
  regenerateCalendarFeed,
  subscriptionLinks,
} from './calendarFeed'
import type { CalendarFeed } from './calendarFeed'

const WHEN = new Intl.DateTimeFormat('fr-BE', {
  day: 'numeric',
  month: 'long',
  hour: '2-digit',
  minute: '2-digit',
})

type Busy = 'enable' | 'regenerate' | 'disable' | null

/**
 * "Ajouter à mon agenda" (docs/decisions.md D170): a secret subscription address of "Mes gardes" that
 * Google Calendar, Apple Calendar and Outlook keep polling — a duty changed in MedVue follows by itself.
 * Nothing is created before the person asks for it; regenerating or disabling stops the old address at once.
 */
export function CalendarSubscriptionModal({ onClose }: { onClose: () => void }) {
  // undefined: loading; null: no address yet.
  const [feed, setFeed] = useState<CalendarFeed | null | undefined>(undefined)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState<Busy>(null)
  const [confirming, setConfirming] = useState<'regenerate' | 'disable' | null>(null)
  const [copied, setCopied] = useState(false)
  const inFlight = useRef(false)

  useEffect(() => {
    let cancelled = false
    fetchCalendarFeed()
      .then((value) => {
        if (!cancelled) setFeed(value)
      })
      .catch(() => {
        if (!cancelled) setError('Impossible de charger votre lien d’agenda.')
      })
    return () => {
      cancelled = true
    }
  }, [])

  async function run(action: Exclude<Busy, null>) {
    if (inFlight.current) return
    inFlight.current = true
    setBusy(action)
    setError(null)
    try {
      if (action === 'disable') {
        await disableCalendarFeed()
        setFeed(null)
      } else {
        setFeed(await (action === 'enable' ? enableCalendarFeed() : regenerateCalendarFeed()))
      }
      setConfirming(null)
      setCopied(false)
    } catch {
      setError(
        action === 'enable'
          ? 'Impossible de créer le lien.'
          : action === 'regenerate'
            ? 'Impossible de générer un nouveau lien.'
            : 'Impossible de désactiver le lien.',
      )
    } finally {
      inFlight.current = false
      setBusy(null)
    }
  }

  async function copy(text: string) {
    try {
      await navigator.clipboard.writeText(text)
      setCopied(true)
    } catch {
      setError('Copie impossible : sélectionnez le lien et copiez-le manuellement.')
    }
  }

  const links = feed ? subscriptionLinks(feed.token) : null

  return (
    <Overlay
      title="Ajouter mes gardes à mon agenda"
      onClose={onClose}
      dismissible={busy === null}
      footer={
        <button type="button" className="btn btn--secondary" onClick={onClose} disabled={busy !== null}>
          Fermer
        </button>
      }
    >
      <p className="muted">
        Vos gardes des plannings publiés apparaissent dans votre agenda et s’y mettent à jour toutes seules
        quand elles changent dans MedVue.
      </p>

      {feed === undefined && !error && (
        <p role="status" className="muted">
          Chargement…
        </p>
      )}

      {feed === null && (
        <button
          type="button"
          className="btn btn--primary"
          onClick={() => run('enable')}
          disabled={busy !== null}
          aria-busy={busy === 'enable'}
          data-autofocus
        >
          <Icon name="calendar" size={18} strokeWidth={2} />
          {busy === 'enable' ? 'Création…' : 'Créer mon lien d’agenda'}
        </button>
      )}

      {feed && links && (
        <>
          <ul className="cal-sub__apps" aria-label="Agendas">
            <li>
              <a
                className="btn btn--secondary btn--full"
                href={links.google}
                target="_blank"
                rel="noopener noreferrer"
              >
                Google Agenda
              </a>
            </li>
            <li>
              <a className="btn btn--secondary btn--full" href={links.webcal}>
                Apple (iPhone, iPad, Mac)
              </a>
            </li>
            <li>
              <a
                className="btn btn--secondary btn--full"
                href={links.outlookCom}
                target="_blank"
                rel="noopener noreferrer"
              >
                Outlook.com
              </a>
            </li>
            <li>
              <a
                className="btn btn--secondary btn--full"
                href={links.outlook365}
                target="_blank"
                rel="noopener noreferrer"
              >
                Outlook Microsoft 365
              </a>
            </li>
          </ul>

          <div className="cal-sub__copy">
            <label htmlFor="cal-sub-url" className="cal-sub__label">
              Autre agenda : copiez ce lien et ajoutez-le « par URL » (abonnement)
            </label>
            <div className="cal-sub__row">
              <input
                id="cal-sub-url"
                className="field__input"
                readOnly
                value={links.feed}
                onFocus={(event) => event.currentTarget.select()}
              />
              <button type="button" className="btn btn--secondary" onClick={() => copy(links.feed)}>
                <Icon name={copied ? 'check' : 'copy'} size={18} strokeWidth={2} />
                {copied ? 'Copié' : 'Copier'}
              </button>
            </div>
          </div>

          <p className="muted cal-sub__note">
            {feed.lastFetchedAt
              ? `Dernière synchronisation par un agenda : ${WHEN.format(new Date(feed.lastFetchedAt))}.`
              : 'Aucun agenda ne s’est encore synchronisé avec ce lien.'}{' '}
            Chaque agenda vérifie les changements à son propre rythme : quelques heures en général, jusqu’à 24
            h pour Google Agenda.
          </p>

          <p className="alert alert--warning">
            <Icon name="lock" size={18} strokeWidth={2} />
            <span>
              Ce lien donne accès à vos gardes sans mot de passe : ne le partagez pas. En cas de doute,
              générez-en un nouveau — l’ancien cessera aussitôt de fonctionner.
            </span>
          </p>

          {confirming === null ? (
            <div className="cal-sub__manage">
              <button
                type="button"
                className="btn btn--ghost btn--sm"
                onClick={() => setConfirming('regenerate')}
              >
                Générer un nouveau lien
              </button>
              <button
                type="button"
                className="btn btn--ghost btn--sm"
                onClick={() => setConfirming('disable')}
              >
                Désactiver le lien
              </button>
            </div>
          ) : (
            <div className="cal-sub__confirm" role="group" aria-label="Confirmation">
              <p>
                {confirming === 'regenerate'
                  ? 'L’ancien lien cessera de fonctionner : vos agendas actuels ne recevront plus vos gardes tant que vous ne les aurez pas abonnés au nouveau.'
                  : 'Vos agendas abonnés ne recevront plus vos gardes. Vous pourrez créer un nouveau lien plus tard.'}
              </p>
              <div className="cal-sub__manage">
                <button
                  type="button"
                  className="btn btn--secondary btn--sm"
                  onClick={() => setConfirming(null)}
                  disabled={busy !== null}
                >
                  Annuler
                </button>
                <button
                  type="button"
                  className="btn btn--danger btn--sm"
                  onClick={() => run(confirming)}
                  disabled={busy !== null}
                  aria-busy={busy !== null}
                  data-autofocus
                >
                  {confirming === 'regenerate'
                    ? busy === 'regenerate'
                      ? 'Génération…'
                      : 'Générer un nouveau lien'
                    : busy === 'disable'
                      ? 'Désactivation…'
                      : 'Désactiver'}
                </button>
              </div>
            </div>
          )}
        </>
      )}

      {error && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>{error}</span>
        </p>
      )}
    </Overlay>
  )
}
