import '@testing-library/jest-dom/vitest'
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { status, stubApi } from '../../../testUtils/stubApi'
import type { PlanningDetail, PlanningLineSummary } from '../types'
import { ExportModal } from './ExportModal'

function lineOf(stableId: string, name: string, position: number, active = true): PlanningLineSummary {
  return {
    stableId,
    name,
    type: position === 0 ? 'PRIMARY' : 'SECONDARY',
    position,
    active,
    team: { stableId: `t-${stableId}`, name },
    planningPeriodStableId: `p-${stableId}`,
    createdAt: '',
    updatedAt: '',
  }
}

const PLANNING: PlanningDetail = {
  stableId: 'plan-1',
  name: 'Gardes Orthopédie',
  creatorStableId: 'u0',
  canManage: false,
  startsAt: '2026-10-01',
  endsAt: '2027-01-01',
  timezone: 'Europe/Brussels',
  createdAt: '',
  updatedAt: '',
  // Deliberately out of order: the dialog follows `position`, and leaves inactive lines out.
  lines: [
    lineOf('l3', 'Urgences', 2),
    lineOf('l1', 'Ligne principale', 0),
    lineOf('lx', 'Ancienne ligne', 3, false),
    lineOf('l2', 'Ligne secondaire', 1),
  ],
}

const EXPORT = 'POST /api/plannings/plan-1/export'

function file(filename = 'Gardes-Orthopedie_2026-10_2026-12.pdf', type = 'application/pdf'): Response {
  return new Response('%PDF-1.7', {
    status: 200,
    headers: { 'Content-Type': type, 'Content-Disposition': `attachment; filename=${filename}` },
  })
}

let clicked: { href: string; download: string }[]

beforeEach(() => {
  clicked = []
  vi.stubGlobal(
    'URL',
    Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:export-1'), revokeObjectURL: vi.fn() }),
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

function renderModal(onClose = vi.fn()) {
  render(<ExportModal planning={PLANNING} onClose={onClose} />)
  return { onClose, dialog: screen.getByRole('dialog', { name: 'Exporter le planning' }) }
}

function lineItems(): string[] {
  return screen
    .getAllByRole('listitem')
    .map((item) => within(item).getByRole('checkbox').getAttribute('aria-label') ?? '')
}

describe('ExportModal', () => {
  it('opens with PDF, the planning name, the whole period and every active line in order under its own name', () => {
    renderModal()

    expect(screen.getByRole('button', { name: 'PDF' })).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByRole('button', { name: 'Excel (.xlsx)' })).toHaveAttribute('aria-pressed', 'false')
    expect(screen.getByLabelText('Titre du document')).toHaveValue('Gardes Orthopédie')
    expect(screen.getByRole('button', { name: 'Planning complet' })).toHaveAttribute('aria-pressed', 'true')
    expect(lineItems()).toEqual([
      'Afficher « Ligne principale » dans l’export',
      'Afficher « Ligne secondaire » dans l’export',
      'Afficher « Urgences » dans l’export',
    ])
    for (const checkbox of screen.getAllByRole('checkbox')) expect(checkbox).toBeChecked()
    expect(screen.getByLabelText('Nom dans l’export pour « Ligne secondaire »')).toHaveValue(
      'Ligne secondaire',
    )
    expect(screen.queryByText('Ancienne ligne')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Aperçu' })).toBeEnabled()
  })

  it('closes with "Fermer" without calling the API', () => {
    const api = stubApi({})
    const { onClose } = renderModal()
    fireEvent.click(screen.getByRole('button', { name: 'Fermer' }))
    expect(onClose).toHaveBeenCalledTimes(1)
    expect(api.calls).toHaveLength(0)
  })

  it('sends exactly the chosen format, title, lines, order and names, then downloads the server file', async () => {
    const api = stubApi({
      [EXPORT]: () => file('Gardes_Bloc-op_2026-10_2026-12.xlsx', 'application/octet-stream'),
    })
    renderModal()

    fireEvent.click(screen.getByRole('button', { name: 'Excel (.xlsx)' }))
    expect(
      screen.queryByRole('button', { name: 'Aperçu' }),
      'The preview is a PDF one.',
    ).not.toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Titre du document'), { target: { value: '  Bloc op  ' } })
    fireEvent.click(screen.getByRole('button', { name: 'Monter « Urgences »' }))
    fireEvent.click(screen.getByRole('button', { name: 'Monter « Urgences »' }))
    expect(screen.getByRole('button', { name: 'Monter « Urgences »' })).toBeDisabled()
    fireEvent.change(screen.getByLabelText('Nom dans l’export pour « Ligne principale »'), {
      target: { value: 'Orthopédie' },
    })
    fireEvent.click(screen.getByRole('checkbox', { name: 'Afficher « Ligne secondaire » dans l’export' }))
    expect(screen.getByLabelText('Nom dans l’export pour « Ligne secondaire »')).toBeDisabled()

    fireEvent.click(screen.getByRole('button', { name: 'Exporter' }))
    await waitFor(() => expect(clicked).toHaveLength(1))

    expect(api.requests('POST', '/api/plannings/plan-1/export').map((call) => call.body)).toEqual([
      {
        format: 'xlsx',
        title: 'Bloc op',
        lines: [
          { stableId: 'l3', label: 'Urgences' },
          { stableId: 'l1', label: 'Orthopédie' },
        ],
      },
    ])
    expect(clicked[0]).toEqual({ href: 'blob:export-1', download: 'Gardes_Bloc-op_2026-10_2026-12.xlsx' })
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:export-1')
  })

  it('moves a line down and back up', () => {
    renderModal()
    fireEvent.click(screen.getByRole('button', { name: 'Descendre « Ligne principale »' }))
    expect(lineItems()[1]).toContain('Ligne principale')
    expect(screen.getByRole('button', { name: 'Descendre « Urgences »' })).toBeDisabled()
    fireEvent.click(screen.getByRole('button', { name: 'Monter « Ligne principale »' }))
    expect(lineItems()[0]).toContain('Ligne principale')
  })

  it('sends a custom period with an exclusive end, the last day chosen included', async () => {
    const api = stubApi({ [EXPORT]: () => file() })
    renderModal()

    fireEvent.click(screen.getByRole('button', { name: 'Période personnalisée' }))
    expect(screen.getByLabelText('Du')).toHaveValue('2026-10-01')
    expect(screen.getByLabelText('Au (inclus)')).toHaveValue('2026-12-31')
    expect(screen.getByLabelText('Au (inclus)')).toHaveAttribute('max', '2026-12-31')
    fireEvent.change(screen.getByLabelText('Du'), { target: { value: '2026-11-01' } })
    fireEvent.change(screen.getByLabelText('Au (inclus)'), { target: { value: '2026-11-30' } })
    fireEvent.click(screen.getByRole('button', { name: 'Exporter' }))
    await waitFor(() => expect(clicked).toHaveLength(1))

    expect(api.requests('POST', '/api/plannings/plan-1/export')[0].body).toMatchObject({
      format: 'pdf',
      from: '2026-11-01',
      to: '2026-12-01',
    })
  })

  it('refuses an invalid period before sending anything', () => {
    const api = stubApi({})
    renderModal()
    fireEvent.click(screen.getByRole('button', { name: 'Période personnalisée' }))

    fireEvent.change(screen.getByLabelText('Du'), { target: { value: '2026-12-10' } })
    fireEvent.change(screen.getByLabelText('Au (inclus)'), { target: { value: '2026-12-01' } })
    expect(screen.getByRole('alert')).toHaveTextContent('La date de début doit précéder la date de fin.')
    expect(screen.getByRole('button', { name: 'Exporter' })).toBeDisabled()

    fireEvent.change(screen.getByLabelText('Au (inclus)'), { target: { value: '2027-01-01' } })
    expect(screen.getByRole('alert')).toHaveTextContent('La période doit rester dans les dates du planning.')

    fireEvent.change(screen.getByLabelText('Du'), { target: { value: '' } })
    expect(screen.getByRole('alert')).toHaveTextContent('Indiquez la date de début et la date de fin.')

    fireEvent.click(screen.getByRole('button', { name: 'Planning complet' }))
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Exporter' })).toBeEnabled()
    expect(api.calls).toHaveLength(0)
  })

  it('refuses to export without any line, without a line name or without a title', () => {
    const api = stubApi({})
    renderModal()

    for (const checkbox of screen.getAllByRole('checkbox')) fireEvent.click(checkbox)
    expect(screen.getByRole('alert')).toHaveTextContent('Sélectionnez au moins une ligne à exporter.')
    expect(screen.getByRole('button', { name: 'Exporter' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Aperçu' })).toBeDisabled()

    fireEvent.click(screen.getByRole('checkbox', { name: 'Afficher « Urgences » dans l’export' }))
    fireEvent.change(screen.getByLabelText('Nom dans l’export pour « Urgences »'), {
      target: { value: '   ' },
    })
    expect(screen.getByRole('alert')).toHaveTextContent('Chaque ligne exportée doit avoir un nom.')

    fireEvent.change(screen.getByLabelText('Nom dans l’export pour « Urgences »'), { target: { value: 'U' } })
    fireEvent.change(screen.getByLabelText('Titre du document'), { target: { value: ' ' } })
    expect(screen.getByText('Donnez un titre au document.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Exporter' })).toBeDisabled()

    fireEvent.click(screen.getByRole('button', { name: 'Exporter' }))
    expect(api.calls).toHaveLength(0)
  })

  it('shows a busy state and never sends twice on a double click', async () => {
    let release: (value: Response) => void = () => {}
    const api = stubApi({ [EXPORT]: () => new Promise<Response>((resolve) => (release = resolve)) })
    const { onClose } = renderModal()

    const button = screen.getByRole('button', { name: 'Exporter' })
    fireEvent.click(button)
    fireEvent.click(button)
    expect(await screen.findByRole('button', { name: 'Export en cours…' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Aperçu' })).toBeDisabled()
    fireEvent.keyDown(document, { key: 'Escape' })
    expect(onClose, 'Not closable while the file is being built.').not.toHaveBeenCalled()

    await act(async () => release(file()))
    await waitFor(() => expect(clicked).toHaveLength(1))
    expect(api.requests('POST', '/api/plannings/plan-1/export')).toHaveLength(1)
    expect(screen.getByRole('button', { name: 'Exporter' })).toBeEnabled()
  })

  it.each([
    [status(409, { error: 'not_yet_published' }), 'Ce planning n’est pas encore publié'],
    [
      status(422, { error: 'validation_failed', violations: { lines: 'Select at least one line.' } }),
      'Sélectionnez au moins une ligne',
    ],
    [
      status(422, { error: 'validation_failed', violations: { 'lines[0].stableId': 'Not an active line.' } }),
      'n’est plus exportable',
    ],
    [
      status(422, { error: 'validation_failed', violations: { to: 'to must not be after the end.' } }),
      'dates du planning',
    ],
    [status(403, {}), 'Vous n’avez pas accès à ce planning.'],
    [status(500, {}), 'L’export n’a pas pu être généré. Réessayez.'],
  ])('explains a refusal from the server (%#)', async (reply, message) => {
    stubApi({ [EXPORT]: () => reply })
    renderModal()
    fireEvent.click(screen.getByRole('button', { name: 'Exporter' }))
    expect(await screen.findByText(new RegExp(message))).toBeInTheDocument()
    expect(clicked).toHaveLength(0)
    expect(screen.getByRole('button', { name: 'Exporter' })).toBeEnabled()
  })

  it('previews the very same PDF from the server, and drops the preview once a parameter changes', async () => {
    const api = stubApi({ [EXPORT]: () => file() })
    renderModal()

    fireEvent.click(screen.getByRole('button', { name: 'Aperçu' }))
    const frame = await screen.findByTitle('Aperçu du PDF')
    expect(frame).toHaveAttribute('src', 'blob:export-1')
    expect(api.requests('POST', '/api/plannings/plan-1/export')[0].body).toMatchObject({
      format: 'pdf',
      title: 'Gardes Orthopédie',
    })
    expect(clicked, 'A preview is not a download.').toHaveLength(0)

    fireEvent.change(screen.getByLabelText('Nom dans l’export pour « Urgences »'), {
      target: { value: 'Urg.' },
    })
    expect(screen.queryByTitle('Aperçu du PDF')).not.toBeInTheDocument()
  })
})
