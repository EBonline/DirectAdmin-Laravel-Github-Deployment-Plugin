import { ref, shallowRef, type Ref, type ShallowRef } from 'vue'
import { toApiError, type ApiError } from '../api/client'
import type { HistoryEntry, LogChunk, TailChunk } from '../api/types'
import { api } from '../services'
import { usePolling } from './resource'

/** What LogView needs to show a log: its text and whether it is being followed live. */
export interface LogSource {
  text: Ref<string>
  loaded: Ref<boolean>
  truncated: Ref<boolean>
  error: ShallowRef<ApiError | null>
  running: Ref<boolean>
}

/**
 * Follows a growing text file on the server: loads its tail, then appends what was added
 * every `interval()` ms (null pauses until `refresh()`). `restart()` starts over, for when
 * `load` now reads another file. With `tailLines`, only the last lines are kept.
 */
export function useTail<C extends TailChunk>(
  load: (offset: number | undefined) => Promise<C>,
  opts: { tailLines?: number; interval: () => number | null; onChunk?: (chunk: C) => void },
) {
  const text = ref('')
  const loaded = ref(false)
  const truncated = ref(false)
  const error = shallowRef<ApiError | null>(null)
  let offset: number | null = null
  let generation = 0

  function keepTail(s: string): string {
    if (!opts.tailLines) {
      return s
    }
    const lines = s.split('\n')
    return lines.length > opts.tailLines + 1 ? lines.slice(-(opts.tailLines + 1)).join('\n') : s
  }

  async function fetchMore(): Promise<boolean> {
    const mine = generation
    try {
      for (let i = 0; i < 20; i++) {
        const r = await load(offset ?? undefined)
        if (mine !== generation) {
          return fetchMore() // restarted meanwhile: this answer was for the previous file
        }
        if (offset === null || r.reset) {
          text.value = keepTail(r.chunk)
          truncated.value = r.truncated
        } else if (r.chunk !== '') {
          text.value = keepTail(text.value + r.chunk)
        }
        offset = r.offset
        opts.onChunk?.(r)
        if (!r.more) {
          break
        }
      }
      loaded.value = true
      error.value = null
      return true
    } catch (e) {
      if (mine !== generation) {
        return fetchMore()
      }
      error.value = toApiError(e)
      loaded.value = true
      return false
    }
  }

  const poller = usePolling(fetchMore, opts.interval)

  function restart(): Promise<void> {
    generation++
    offset = null
    text.value = ''
    truncated.value = false
    loaded.value = false
    error.value = null
    return poller.trigger()
  }

  return { text, loaded, truncated, error, refresh: poller.trigger, restart }
}

/**
 * Follows a deployment log: loads the tail, then appends what the server adds every 1.5s
 * while the deployment runs. With `tailLines`, only the last lines are kept (inline preview).
 */
export function useLogStream(siteId: string, logId: string, opts: { tailLines?: number; interval?: number } = {}) {
  const running = ref(false)
  const entry = shallowRef<HistoryEntry | null>(null)
  const site = shallowRef<LogChunk['site'] | null>(null)
  const tail = useTail((offset) => api().get<LogChunk>('log', { site: siteId, id: logId, offset }), {
    tailLines: opts.tailLines,
    interval: (): number | null => (running.value || !tail.loaded.value ? (opts.interval ?? 1500) : null),
    onChunk: (r) => {
      running.value = r.running
      entry.value = r.entry
      site.value = r.site
    },
  })

  return { text: tail.text, running, loaded: tail.loaded, truncated: tail.truncated, entry, site, error: tail.error, refresh: tail.refresh }
}
