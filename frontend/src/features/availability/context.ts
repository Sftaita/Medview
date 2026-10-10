import { createContext } from 'react'
import type { AcknowledgementKind, AvailabilityCollection } from './collectionTypes'
import type { DayRange } from './selection'
import type { UserAvailabilityPeriod } from './types'

export type MyAvailabilityContextValue = {
  /**
   * The calendar as the user sees it — *optimistic*: an edit is here at once,
   * before the server has answered. null until the first load.
   */
  ranges: DayRange[] | null
  /**
   * Days of leave imported from SurgicalHub (merged runs), read-only: drawn
   * under `ranges`, never edited from here (docs/surgicalhub-integration.md §10).
   */
  imported: DayRange[]
  /** The imported periods themselves (each with its own dates and `deletable`), for the read-only list. */
  importedPeriods: UserAvailabilityPeriod[]
  /** Removes an imported period no association keeps in sync any more (§9); rejects with the ApiError. */
  removeImported: (stableId: string) => Promise<void>
  loadError: string | null
  /** A save is in flight (or about to start). Never a reason to block editing. */
  syncing: boolean
  /** Set when a save failed and the screen was put back to what the server holds. */
  syncError: string | null
  /** Changes on every completed save round: lets a page say "Enregistré". */
  lastSavedAt: number | null
  /** Applies an edit to the screen at once and saves it in the background — there is no "Enregistrer". */
  editRanges: (updater: (previous: DayRange[]) => DayRange[]) => void
  dismissSyncError: () => void
  /** Re-reads the calendar from the server (ignored while an edit is unsaved). */
  reload: () => Promise<void>

  /** The open collections the user has to answer or has answered; null until loaded. */
  collections: AvailabilityCollection[] | null
  refreshCollections: () => Promise<void>
  /** The current user's own confirmation. Rejects with the ApiError so the caller can explain it. */
  acknowledge: (collectionStableId: string, kind: AcknowledgementKind) => Promise<void>
}

export const MyAvailabilityContext = createContext<MyAvailabilityContextValue | null>(null)
