import '@testing-library/jest-dom/vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { Field, PasswordField } from './Field'

describe('Field', () => {
  it('links the label to the input', () => {
    render(<Field label="Email" type="email" />)
    expect(screen.getByLabelText('Email')).toHaveAttribute('type', 'email')
  })

  it('describes the input with its hint', () => {
    render(<Field label="Téléphone" hint="Format international" />)
    const input = screen.getByLabelText('Téléphone')
    expect(input).toHaveAccessibleDescription('Format international')
    expect(input).not.toHaveAttribute('aria-invalid')
  })

  it('flags the input as invalid and shows the error instead of the hint', () => {
    render(<Field label="Téléphone" hint="Format international" error="Numéro invalide" />)
    const input = screen.getByLabelText('Téléphone')
    expect(input).toHaveAttribute('aria-invalid', 'true')
    expect(input).toHaveAccessibleDescription('Numéro invalide')
  })
})

describe('PasswordField', () => {
  it('hides the password by default and toggles its visibility', () => {
    render(<PasswordField label="Mot de passe" />)
    const input = screen.getByLabelText('Mot de passe')
    expect(input).toHaveAttribute('type', 'password')

    fireEvent.click(screen.getByRole('button', { name: 'Afficher le mot de passe' }))
    expect(input).toHaveAttribute('type', 'text')

    fireEvent.click(screen.getByRole('button', { name: 'Masquer le mot de passe' }))
    expect(input).toHaveAttribute('type', 'password')
  })

  it('keeps the toggle out of the form submission', () => {
    render(
      <form onSubmit={(event) => event.preventDefault()}>
        <PasswordField label="Mot de passe" />
      </form>,
    )
    expect(screen.getByRole('button', { name: 'Afficher le mot de passe' })).toHaveAttribute('type', 'button')
  })
})
