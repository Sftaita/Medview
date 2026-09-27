import '@testing-library/jest-dom/vitest'
import { act, cleanup, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { stubApi } from '../../../testUtils/stubApi'
import type { PlanningJob } from '../pilot/types'
import { JOB_POLL_INTERVAL_MS, usePlanningJob } from './usePlanningJob'

function job(overrides: Partial<PlanningJob> = {}): PlanningJob {
  return {
    stableId: 'job-1',
    kind: 'GENERATE',
    status: 'RUNNING',
    requestedBy: { firstName: 'Camille', lastName: 'Dupont' },
    createdAt: '2026-09-27T10:00:00+00:00',
    startedAt: '2026-09-27T10:00:01+00:00',
    finishedAt: null,
    failureCode: null,
    outcome: null,
    ...overrides,
  }
}

function Probe({ onFinished, launch }: { onFinished: (job: PlanningJob) => void; launch?: PlanningJob }) {
  const state = usePlanningJob('plan-1', onFinished)
  return (
    <div>
      <span data-testid="status">{state.job?.status ?? 'none'}</span>
      <span data-testid="finished">{state.finished?.status ?? 'none'}</span>
      {launch && (
        <button type="button" onClick={() => state.track(launch)}>
          launch
        </button>
      )}
    </div>
  )
}

/** Lets the pending fetch promises settle under fake timers. */
async function flush() {
  await act(async () => {
    for (let i = 0; i < 5; i++) await Promise.resolve()
  })
}

beforeEach(() => {
  vi.useFakeTimers()
  localStorage.setItem('medvue.auth.token', 'jwt')
})
afterEach(() => {
  cleanup()
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

describe('usePlanningJob (docs/decisions.md D149)', () => {
  it('finds a running job again on mount — coming back to the page — and polls it until it ends', async () => {
    const replies = [
      job({ status: 'RUNNING' }),
      job({ status: 'RUNNING' }),
      job({ status: 'SUCCEEDED', outcome: { lines: [], coverage: 'COMPLETE' } }),
    ]
    const api = stubApi({
      'GET /api/plannings/plan-1/jobs/latest': () => ({
        job: replies.shift() ?? job({ status: 'SUCCEEDED' }),
      }),
    })
    const onFinished = vi.fn()
    render(<Probe onFinished={onFinished} />)

    await flush()
    expect(screen.getByTestId('status')).toHaveTextContent('RUNNING')
    expect(api.requests('GET', '/api/plannings/plan-1/jobs/latest')).toHaveLength(1)

    await act(async () => vi.advanceTimersByTime(JOB_POLL_INTERVAL_MS))
    await flush()
    expect(api.requests('GET', '/api/plannings/plan-1/jobs/latest')).toHaveLength(2)

    await act(async () => vi.advanceTimersByTime(JOB_POLL_INTERVAL_MS))
    await flush()
    expect(screen.getByTestId('status')).toHaveTextContent('SUCCEEDED')
    expect(screen.getByTestId('finished')).toHaveTextContent('SUCCEEDED')
    expect(onFinished).toHaveBeenCalledTimes(1)

    // Terminal: the polling stops.
    await act(async () => vi.advanceTimersByTime(JOB_POLL_INTERVAL_MS * 5))
    await flush()
    expect(api.requests('GET', '/api/plannings/plan-1/jobs/latest')).toHaveLength(3)
  })

  it('never polls, nor announces anything, for a job that was already finished when the page opened', async () => {
    const api = stubApi({
      'GET /api/plannings/plan-1/jobs/latest': () => ({ job: job({ status: 'SUCCEEDED' }) }),
    })
    const onFinished = vi.fn()
    render(<Probe onFinished={onFinished} />)

    await flush()
    await act(async () => vi.advanceTimersByTime(JOB_POLL_INTERVAL_MS * 3))
    await flush()
    expect(api.requests('GET', '/api/plannings/plan-1/jobs/latest')).toHaveLength(1)
    expect(screen.getByTestId('finished')).toHaveTextContent('none')
    expect(onFinished).not.toHaveBeenCalled()
  })

  it('follows a job just launched from the screen, and reports its failure', async () => {
    let reply: PlanningJob | null = null
    stubApi({ 'GET /api/plannings/plan-1/jobs/latest': () => ({ job: reply }) })
    const onFinished = vi.fn()
    render(<Probe onFinished={onFinished} launch={job({ status: 'QUEUED' })} />)
    await flush()
    expect(screen.getByTestId('status')).toHaveTextContent('none')

    await act(async () => screen.getByRole('button', { name: 'launch' }).click())
    expect(screen.getByTestId('status')).toHaveTextContent('QUEUED')

    reply = job({ status: 'FAILED', failureCode: 'unexpected_error' })
    await act(async () => vi.advanceTimersByTime(JOB_POLL_INTERVAL_MS))
    await flush()
    expect(screen.getByTestId('status')).toHaveTextContent('FAILED')
    expect(onFinished).toHaveBeenCalledWith(expect.objectContaining({ status: 'FAILED' }))
  })
})
