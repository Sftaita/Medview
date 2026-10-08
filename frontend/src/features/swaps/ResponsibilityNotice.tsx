import { Icon } from '../../components/Icon'

/**
 * The responsibility warning (docs/duty-swaps.md §1), shown before any request is sent and on every pending
 * request: asking for a swap never releases anyone from their duty.
 */
export function ResponsibilityNotice({ compact = false }: { compact?: boolean }) {
  return (
    <div role="note" aria-label="Responsabilité de votre garde" className="alert alert--warning swap-notice">
      <Icon name="alert" size={20} strokeWidth={2} />
      <div className="alert__body">
        <strong>Responsabilité de votre garde</strong>
        <p>
          Tant qu’un autre membre n’a pas accepté votre demande et que l’échange n’a pas été confirmé dans
          MedVue, vous restez personnellement responsable de votre garde initiale.
        </p>
        {!compact && (
          <p>
            L’envoi d’une demande d’échange ne vous décharge pas de votre responsabilité et ne modifie pas le
            planning.
          </p>
        )}
      </div>
    </div>
  )
}
