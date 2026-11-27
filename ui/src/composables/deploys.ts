import { onBeforeUnmount, watch } from 'vue'
import type { HistoryEntry, SiteStatus } from '../api/types'
import { duration } from '../utils/format'
import { toasts } from './toasts'

// Notices when a queued or running deployment finishes (between two polls) and tells the
// user how it went, with a link to the log. Also shows the state in the browser tab title,
// so a deployment can be followed from another tab.

let originalTitle: string | null = null

export function setTabStatus(status: string | null): void {
  if (typeof document === 'undefined') {
    return
  }
  originalTitle ??= document.title
  document.title = status ? `${status} · ${originalTitle}` : originalTitle
}

export function isBusy(s: Pick<SiteStatus, 'queued' | 'running'>): boolean {
  return s.queued !== null || s.running !== null
}

export function announceFinished(hostname: string, siteId: string, e: HistoryEntry, linkToLog = true): 'ok' | 'err' {
  const action = linkToLog ? { label: 'View log', to: { name: 'log', query: { site: siteId, id: e.id } } } : undefined
  if (e.status === 'success') {
    const took = e.finished_at ? ` in ${duration(e.finished_at - e.started_at)}` : ''
    toasts.success(
      e.type === 'rollback' ? `${hostname} is back on release ${e.release}.` : `Deployed ${e.label} to ${hostname}${took}.`,
      { action },
    )
    return 'ok'
  }
  const what = e.type === 'rollback' ? `Rollback of ${hostname}` : `Deployment of ${e.label} to ${hostname}`
  const how = e.status === 'failed_after_activation' ? 'failed after it went live' : 'failed'
  const keep = e.status === 'failed' && e.type === 'deploy' ? ' The site keeps running the previous release.' : ''
  toasts.error(`${what} ${how}${e.error ? `: ${e.error}` : '.'}${keep}`, { action })
  return 'err'
}

export function useDeployWatch(source: () => SiteStatus[] | null | undefined): void {
  const previous = new Map<string, { busy: boolean; lastId: string | null; lastStatus: string | null }>()
  let flashTimer: ReturnType<typeof setTimeout> | null = null
  let flashing = false

  function flash(status: string): void {
    flashing = true
    setTabStatus(status)
    if (flashTimer !== null) {
      clearTimeout(flashTimer)
    }
    flashTimer = setTimeout(() => {
      flashing = false
      setTabStatus(null)
    }, 20000)
  }

  watch(
    source,
    (list) => {
      if (!list) {
        return
      }
      let anyBusy = false
      for (const s of list) {
        const busy = isBusy(s)
        anyBusy ||= busy
        const before = previous.get(s.site.id)
        const last = s.last
        if (before?.busy && !busy && last && last.status !== 'running' && (last.id !== before.lastId || last.status !== before.lastStatus)) {
          flash(announceFinished(s.site.hostname, s.site.id, last) === 'ok' ? '✓ Deployed' : '✗ Failed')
        }
        previous.set(s.site.id, { busy, lastId: last?.id ?? null, lastStatus: last?.status ?? null })
      }
      if (anyBusy) {
        setTabStatus(list.some((s) => s.running) ? '● Deploying…' : '● Queued')
      } else if (!flashing) {
        setTabStatus(null)
      }
    },
    { immediate: true },
  )

  onBeforeUnmount(() => {
    if (flashTimer !== null) {
      clearTimeout(flashTimer)
    }
    setTabStatus(null)
  })
}
