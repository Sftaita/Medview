import { useRef, useState } from 'react'
import { Icon } from '../../../components/Icon'
import { ApiError } from '../../../lib/apiClient'
import { downloadAbsencesPdf } from './api'

type Props = {
  planningStableId: string
  /** Used only when the server's file name cannot be read. */
  planningName: string
}

/**
 * "Exporter les absences (PDF)" (docs/decisions.md D181): downloads the
 * calendar and the per-member summary of everyone's unavailabilities over the
 * planning period. Shown in the availability follow-up, which only managers
 * see — the server checks the right anyway.
 */
export function AbsenceExportButton({ planningStableId, planningName }: Props) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  // A ref, not state: two clicks in the same tick both read the state as "idle".
  const inFlight = useRef(false)

  async function download() {
    if (inFlight.current) return
    inFlight.current = true
    setBusy(true)
    setError(null)
    try {
      const file = await downloadAbsencesPdf(planningStableId)
      const url = URL.createObjectURL(file.blob)
      const link = document.createElement('a')
      link.href = url
      link.download = file.filename ?? `MedVue_Absences_${planningName.replace(/[^\p{L}\p{N}]+/gu, '-')}.pdf`
      document.body.appendChild(link)
      link.click()
      link.remove()
      URL.revokeObjectURL(url)
    } catch (err) {
      setError(
        err instanceof ApiError && err.status === 403
          ? 'Vous n’avez pas le droit d’exporter les absences de ce planning.'
          : 'Impossible de générer le PDF des absences. Réessayez dans un instant.',
      )
    } finally {
      inFlight.current = false
      setBusy(false)
    }
  }

  return (
    <>
      <button
        type="button"
        className="pd-btn pd-btn-ghost"
        onClick={download}
        disabled={busy}
        aria-busy={busy}
      >
        <Icon name="download" size={18} strokeWidth={2} />
        {busy ? 'Génération du PDF…' : 'Exporter les absences (PDF)'}
      </button>
      {error && (
        <p role="alert" className="alert alert--error pd-export-error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>{error}</span>
        </p>
      )}
    </>
  )
}
