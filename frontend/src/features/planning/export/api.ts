import { apiDownload, type DownloadedFile } from '../../../lib/apiClient'
import type { ExportRequest } from './exportModel'

/**
 * The current calendar of a published planning as a file (docs/planning-export.md).
 * Never a publication: it only reads.
 */
export function exportPlanning(planningStableId: string, request: ExportRequest): Promise<DownloadedFile> {
  return apiDownload(`/api/plannings/${planningStableId}/export`, request)
}
