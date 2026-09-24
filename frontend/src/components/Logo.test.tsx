import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { Logo } from './Logo'

describe('Logo', () => {
  it('draws the official mark at the requested size, hidden from assistive tech', () => {
    render(<Logo size={44} />)
    const svg = screen.getByTestId('medvue-logo')
    expect(svg).toHaveAttribute('width', '44')
    expect(svg).toHaveAttribute('aria-hidden', 'true')
    expect(svg.querySelectorAll('g rect')).toHaveLength(5)
    expect(svg.querySelector('rect')).toHaveAttribute('fill', 'var(--green-500)')
  })

  it('swaps badge and cells when inverse', () => {
    render(<Logo inverse />)
    const svg = screen.getByTestId('medvue-logo')
    expect(svg.querySelector('rect')).toHaveAttribute('fill', 'var(--white)')
    expect(svg.querySelector('g')).toHaveAttribute('fill', 'var(--green-500)')
  })
})
