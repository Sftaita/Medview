import { useContext } from 'react'
import { MyAvailabilityContext, type MyAvailabilityContextValue } from './context'

export function useMyAvailability(): MyAvailabilityContextValue {
  const value = useContext(MyAvailabilityContext)
  if (!value) {
    throw new Error('useMyAvailability must be used inside <MyAvailabilityProvider>')
  }
  return value
}
