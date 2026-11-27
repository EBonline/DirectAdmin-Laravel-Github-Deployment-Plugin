import type { DeployStatus, HistoryEntry } from '../api/types'

/** "just now", "12s ago", "5 min ago", "3 h ago", "2 d ago"; `ts` and `now` in unix seconds. */
export function ago(ts: number | null | undefined, now: number): string {
  if (!ts) {
    return 'never'
  }
  const d = Math.floor(now - ts)
  if (d < 5) {
    return 'just now'
  }
  if (d < 60) {
    return `${d}s ago`
  }
  if (d < 3600) {
    return `${Math.floor(d / 60)} min ago`
  }
  if (d < 86400) {
    return `${Math.floor(d / 3600)} h ago`
  }
  return `${Math.floor(d / 86400)} d ago`
}

/** "45s", "1m 12s", "1h 02m". */
export function duration(seconds: number | null | undefined): string {
  if (seconds === null || seconds === undefined || seconds < 0 || !Number.isFinite(seconds)) {
    return '—'
  }
  const s = Math.floor(seconds)
  if (s < 60) {
    return `${s}s`
  }
  if (s < 3600) {
    return `${Math.floor(s / 60)}m ${s % 60}s`
  }
  return `${Math.floor(s / 3600)}h ${String(Math.floor((s % 3600) / 60)).padStart(2, '0')}m`
}

/** A running clock: "0:07", "1:23", "1:02:03". */
export function clock(seconds: number): string {
  const s = Math.max(0, Math.floor(seconds))
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  const sec = String(s % 60).padStart(2, '0')
  return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${sec}` : `${m}:${sec}`
}

export function dateTime(ts: number | null | undefined): string {
  if (!ts) {
    return '—'
  }
  return new Date(ts * 1000).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}

export function shortSha(sha: string | null | undefined): string {
  return sha ? sha.slice(0, 7) : ''
}

export function trim(text: string | null | undefined, max: number): string {
  const chars = Array.from(text ?? '')
  return chars.length <= max ? chars.join('') : `${chars.slice(0, max).join('').trimEnd()}…`
}

export function bytes(n: number): string {
  if (n < 1024) {
    return `${n} B`
  }
  return `${(n / 1024).toFixed(n < 10240 ? 1 : 0)} KB`
}

export type Tone = 'ok' | 'err' | 'warn' | 'run' | 'muted'

export function statusInfo(status: DeployStatus | null | undefined): { label: string; tone: Tone } {
  switch (status) {
    case 'success':
      return { label: 'Deployed', tone: 'ok' }
    case 'running':
      return { label: 'Deploying…', tone: 'run' }
    case 'queued':
      return { label: 'Queued', tone: 'run' }
    case 'failed':
      return { label: 'Failed', tone: 'err' }
    case 'failed_after_activation':
      return { label: 'Failed after activation', tone: 'warn' }
    default:
      return { label: 'Not deployed', tone: 'muted' }
  }
}

/** One line describing a history entry, e.g. "v1.2.0" or "rollback to release 2611241430". */
export function entryTitle(e: HistoryEntry): string {
  return e.type === 'rollback' ? `rollback to release ${e.release}` : (e.label ?? '')
}

export function capitalize(s: string | null | undefined): string {
  return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''
}

export function plural(n: number, one: string, many = `${one}s`): string {
  return `${n} ${n === 1 ? one : many}`
}
