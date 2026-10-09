import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { makeStatus } from '../../../testUtils/pilotFixtures'
import { status as httpStatus, stubApi } from '../../../testUtils/stubApi'
import { AbsenceExportButton } from './AbsenceExportButton'
import { CollectionStatusPanel } from './CollectionStatusPanel'

const EXPORT = 'GET /api/plannings/plan-1/availability-export.pdf'
const FILENAME = 'MedVue_Absences_Gardes-Orthopedie_2027-01-15_2027-03-14.pdf'

let clicked: { href: string; download: string }[] = []

beforeEach(() => {
  localStorage.setItem('medvue.auth.token', 'jwt')
  clicked = []
  vi.stubGlobal(
    'URL',
    Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:absences-1'), revokeObjectURL: vi.fn() }),
  )
  vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
    clicked.push({ href: this.href, download: this.download })
  })
})

afterEach(() => {
  cleanup()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

const pdf = () =>
  new Response('%PDF-1.7', {
    status: 200,
    headers: { 'Content-Type': 'application/pdf', 'Content-Disposition': `attachment; filename=${FILENAME}` },
  })

describe('Exporter les absences (PDF)', () => {
  it('is offered in the availability follow-up', () => {
    render(<CollectionStatusPanel status={makeStatus()} onChanged={vi.fn()} />)

    expect(screen.getByRole('button', { name: 'Exporter les absences (PDF)' })).toBeInTheDocument()
  })

  it('is offered even when nobody is pending and no deadline can be set', () => {
    const base = makeStatus()
    render(
      <CollectionStatusPanel
        status={makeStatus({ summary: { ...base.summary, pendingCount: 0 } })}
        onChanged={vi.fn()}
      />,
    )

    expect(screen.getByRole('button', { name: 'Exporter les absences (PDF)' })).toBeInTheDocument()
  })

  it('downloads the PDF of this planning under the server’s file name, sending nothing but the planning', async () => {
    let release: (value: Response) => void = () => {}
    const api = stubApi({ [EXPORT]: () => new Promise<Response>((resolve) => (release = resolve)) })
    render(<AbsenceExportButton planningStableId="plan-1" planningName="Gardes Orthopédie" />)

    fireEvent.click(screen.getByRole('button', { name: 'Exporter les absences (PDF)' }))
    fireEvent.click(screen.getByRole('button', { name: 'Génération du PDF…' }))

    // While the server works: a loading label, the button disabled, a single request.
    const busy = screen.getByRole('button', { name: 'Génération du PDF…' })
    expect(busy).toBeDisabled()
    expect(busy).toHaveAttribute('aria-busy', 'true')

    release(pdf())
    await waitFor(() => expect(clicked).toEqual([{ href: 'blob:absences-1', download: FILENAME }]))
    expect(api.requests('GET', '/api/plannings/plan-1/availability-export.pdf')).toHaveLength(1)
    expect(api.requests('GET', '/api/plannings/plan-1/availability-export.pdf')[0].body).toBeNull()
    expect(screen.getByRole('button', { name: 'Exporter les absences (PDF)' })).toBeEnabled()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('says so when the PDF cannot be produced, and lets the user try again', async () => {
    let fail = true
    stubApi({ [EXPORT]: () => (fail ? httpStatus(500, { error: 'server_error' }) : pdf()) })
    render(<AbsenceExportButton planningStableId="plan-1" planningName="Gardes Orthopédie" />)

    fireEvent.click(screen.getByRole('button', { name: 'Exporter les absences (PDF)' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Impossible de générer le PDF des absences. Réessayez dans un instant.',
    )
    expect(clicked).toEqual([])

    fail = false
    fireEvent.click(screen.getByRole('button', { name: 'Exporter les absences (PDF)' }))
    await waitFor(() => expect(clicked).toHaveLength(1))
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('explains a refusal', async () => {
    stubApi({ [EXPORT]: () => httpStatus(403, { error: 'forbidden' }) })
    render(<AbsenceExportButton planningStableId="plan-1" planningName="Gardes Orthopédie" />)

    fireEvent.click(screen.getByRole('button', { name: 'Exporter les absences (PDF)' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Vous n’avez pas le droit d’exporter les absences de ce planning.',
    )
  })
})
