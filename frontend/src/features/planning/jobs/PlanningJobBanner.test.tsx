import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { PlanningJob } from '../pilot/types'
import { PlanningJobBanner } from './PlanningJobBanner'

afterEach(cleanup)

function job(overrides: Partial<PlanningJob> = {}): PlanningJob {
  return {
    stableId: 'job-1',
    kind: 'GENERATE',
    status: 'RUNNING',
    requestedBy: { firstName: 'Camille', lastName: 'Dupont' },
    createdAt: '2026-09-27T10:00:00+00:00',
    startedAt: null,
    finishedAt: null,
    failureCode: null,
    outcome: null,
    ...overrides,
  }
}

function renderBanner(current: PlanningJob | null, finished: PlanningJob | null = null, canRelaunch = true) {
  const handlers = { onRelaunch: vi.fn(), onShowDetail: vi.fn(), onDismiss: vi.fn() }
  render(<PlanningJobBanner job={current} finished={finished} canRelaunch={canRelaunch} {...handlers} />)
  return handlers
}

describe('PlanningJobBanner (docs/decisions.md D149)', () => {
  it('says a generation is running, with no invented percentage, and that the page can be left', () => {
    renderBanner(job())
    const banner = screen.getByRole('status', { name: 'Calcul en cours' })
    expect(banner).toHaveTextContent('Génération du planning en cours…')
    expect(banner).toHaveTextContent('Vous pouvez quitter cette page. Le calcul continuera en arrière-plan.')
    expect(banner).not.toHaveTextContent('%')
  })

  it('says when a queued job has not started yet, and names a completion as such', () => {
    renderBanner(job({ kind: 'COMPLETE', status: 'QUEUED' }))
    expect(screen.getByRole('status')).toHaveTextContent('Complétion du planning en cours…')
    expect(screen.getByRole('status')).toHaveTextContent('En attente de démarrage.')
  })

  it('reports a failure in plain words — never an internal message — and offers "Relancer"', () => {
    const { onRelaunch } = renderBanner(job({ status: 'FAILED', failureCode: 'unexpected_error' }))
    const alert = screen.getByRole('alert')
    expect(alert).toHaveTextContent('La génération du planning a échoué.')
    expect(alert).toHaveTextContent('Une erreur technique est survenue.')

    fireEvent.click(screen.getByRole('button', { name: 'Relancer' }))
    expect(onRelaunch).toHaveBeenCalledWith('GENERATE')
  })

  it('explains a completion refused because the calendar changed meanwhile', () => {
    renderBanner(job({ kind: 'COMPLETE', status: 'FAILED', failureCode: 'calendar_changed' }))
    expect(screen.getByRole('alert')).toHaveTextContent('La complétion automatique a échoué.')
    expect(screen.getByRole('alert')).toHaveTextContent('rien n’a été enregistré')
  })

  it('offers no relaunch to someone who may not start one', () => {
    renderBanner(job({ status: 'FAILED', failureCode: 'worker_lost' }), null, false)
    expect(screen.getByRole('alert')).toHaveTextContent('Le calcul a été interrompu avant sa fin.')
    expect(screen.queryByRole('button', { name: 'Relancer' })).not.toBeInTheDocument()
  })

  it('announces a generation that just finished — incomplete coverage included — with its detail', () => {
    const done = job({
      status: 'SUCCEEDED',
      outcome: { lines: [{ unassignedDutyCount: 2 }], coverage: 'INCOMPLETE' },
    })
    const { onShowDetail, onDismiss } = renderBanner(done, done)
    expect(screen.getByRole('status')).toHaveTextContent(
      'couverture incomplète : 2 gardes obligatoires sans titulaire',
    )

    fireEvent.click(screen.getByRole('button', { name: 'Voir le détail' }))
    expect(onShowDetail).toHaveBeenCalledWith(done)
    fireEvent.click(screen.getByRole('button', { name: 'Masquer' }))
    expect(onDismiss).toHaveBeenCalled()
  })

  it('announces a finished completion with what it filled', () => {
    const done = job({
      kind: 'COMPLETE',
      status: 'SUCCEEDED',
      outcome: {
        lines: [{ filledUnitCount: 2, remainingUncoveredRequiredUnitCount: 0 }],
        coverage: 'COMPLETE',
      },
    })
    renderBanner(done, done)
    expect(screen.getByRole('status')).toHaveTextContent(
      '2 gardes ou blocs attribués automatiquement, sans toucher aux affectations existantes.',
    )
  })

  it('claims nothing about the coverage when the detail is not visible to this person (a member)', () => {
    const done = job({ status: 'SUCCEEDED', outcome: null })
    renderBanner(done, done, false)
    expect(screen.getByRole('status')).toHaveTextContent('Génération terminée : le planning est à jour.')
    expect(screen.queryByRole('button', { name: 'Voir le détail' })).not.toBeInTheDocument()
  })

  it('shows nothing for an old success it did not see finish', () => {
    const { container } = render(
      <PlanningJobBanner
        job={job({ status: 'SUCCEEDED' })}
        finished={null}
        canRelaunch
        onRelaunch={vi.fn()}
        onDismiss={vi.fn()}
      />,
    )
    expect(container).toBeEmptyDOMElement()
  })
})
