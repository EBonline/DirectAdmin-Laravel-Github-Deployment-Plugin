import { ref, shallowRef } from 'vue'
import { toApiError, type ApiError } from '../api/client'
import type { HistoryEntry, LogChunk } from '../api/types'
import { api } from '../services'
import { usePolling } from './resource'

/**
 * Follows a deployment log: loads the tail, then appends what the server adds every 1.5s
 * while the deployment runs. With `tailLines`, only the last lines are kept (inline preview).
 */
export function useLogStream(siteId: string, logId: string, opts: { tailLines?: number; interval?: number } = {}) {
  const text = ref('')
  const running = ref(false)
  const loaded = ref(false)
  const truncated = ref(false)
  const entry = shallowRef<HistoryEntry | null>(null)
  const site = shallowRef<LogChunk['site'] | null>(null)
  const error = shallowRef<ApiError | null>(null)
  let offset: number | null = null

  function keepTail(s: string): string {
    if (!opts.tailLines) {
      return s
    }
    const lines = s.split('\n')
    return lines.length > opts.tailLines + 1 ? lines.slice(-(opts.tailLines + 1)).join('\n') : s
  }

  async function fetchMore(): Promise<boolean> {
    try {
      for (let i = 0; i < 20; i++) {
        const r = await api().get<LogChunk>('log', { site: siteId, id: logId, offset: offset ?? undefined })
        if (offset === null || r.reset) {
          text.value = keepTail(r.chunk)
          truncated.value = r.truncated
        } else if (r.chunk !== '') {
          text.value = keepTail(text.value + r.chunk)
        }
        offset = r.offset
        running.value = r.running
        entry.value = r.entry
        site.value = r.site
        if (!r.more) {
          break
        }
      }
      loaded.value = true
      error.value = null
      return true
    } catch (e) {
      error.value = toApiError(e)
      loaded.value = true
      return false
    }
  }

  const poller = usePolling(fetchMore, () => (running.value || !loaded.value ? (opts.interval ?? 1500) : null))

  return { text, running, loaded, truncated, entry, site, error, refresh: poller.trigger }
}
