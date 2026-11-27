import { reactive } from 'vue'
import { api } from '../services'
import { toasts } from './toasts'

// Tracks whether the server is reachable and whether the DirectAdmin session is still valid.
// While the server is unreachable it re-checks every 10 seconds; pages register to refresh
// themselves as soon as the connection is back.

export const RETRY_MS = 10000

export const connection = reactive({
  /** The server could not be reached by the last request. */
  down: false,
  /** When the next automatic check runs (ms epoch), while down. */
  retryAt: 0,
  checking: false,
  sessionExpired: false,
})

const listeners = new Set<() => void>()
let retryTimer: ReturnType<typeof setTimeout> | null = null

/** Call `fn` when the connection or session comes back. Returns an unsubscribe function. */
export function onReconnect(fn: () => void): () => void {
  listeners.add(fn)
  return () => listeners.delete(fn)
}

function notify(): void {
  for (const fn of [...listeners]) {
    fn()
  }
}

function scheduleRetry(): void {
  if (retryTimer !== null) {
    clearTimeout(retryTimer)
  }
  connection.retryAt = Date.now() + RETRY_MS
  retryTimer = setTimeout(() => void checkNow(), RETRY_MS)
}

export function reportConnection(ok: boolean): void {
  if (ok) {
    if (connection.down) {
      connection.down = false
      if (retryTimer !== null) {
        clearTimeout(retryTimer)
        retryTimer = null
      }
      toasts.dismissTag('connection')
      toasts.success('Connection restored.')
      notify()
    }
    return
  }
  if (!connection.down) {
    connection.down = true
    scheduleRetry()
  }
}

export function reportSessionExpired(): void {
  connection.sessionExpired = true
}

/** Checks right away whether the server answers again (and refreshes the CSRF token). */
export async function checkNow(): Promise<boolean> {
  if (connection.checking) {
    return false
  }
  connection.checking = true
  try {
    await api().refreshCsrf()
    if (connection.sessionExpired) {
      connection.sessionExpired = false
      toasts.success('You are logged in again. You can continue where you left off.')
      notify()
    }
    return true
  } catch {
    return false
  } finally {
    connection.checking = false
    if (connection.down) {
      scheduleRetry()
    }
  }
}
