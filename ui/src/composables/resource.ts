import { onBeforeUnmount, onMounted, ref, shallowRef, type Ref, type ShallowRef } from 'vue'
import { ApiError, toApiError } from '../api/client'
import { onReconnect } from './connection'
import { toasts } from './toasts'

export interface Resource<T> {
  data: ShallowRef<T | null>
  error: ShallowRef<ApiError | null>
  loading: Ref<boolean>
  /** When the data was last loaded successfully (ms epoch). */
  updatedAt: Ref<number | null>
  /** Loads again; resolves false on failure (the error is kept in `error`). */
  refresh(): Promise<boolean>
}

/** Data loaded from the API, with loading/error state. Late answers never overwrite newer ones. */
export function useResource<T>(loader: () => Promise<T>): Resource<T> {
  const data = shallowRef<T | null>(null)
  const error = shallowRef<ApiError | null>(null)
  const loading = ref(false)
  const updatedAt = ref<number | null>(null)
  let seq = 0

  async function refresh(): Promise<boolean> {
    const mine = ++seq
    loading.value = true
    try {
      const result = await loader()
      if (mine === seq) {
        data.value = result
        error.value = null
        updatedAt.value = Date.now()
      }
      return true
    } catch (e) {
      if (mine === seq) {
        error.value = toApiError(e)
      }
      return false
    } finally {
      if (mine === seq) {
        loading.value = false
      }
    }
  }

  // A page that could not load because the server was unreachable or the session had
  // ended loads again by itself once that is resolved.
  let unsubscribe: (() => void) | null = null
  onMounted(() => {
    unsubscribe = onReconnect(() => {
      if (error.value && (error.value.isConnection || error.value.code === 'session')) {
        void refresh()
      }
    })
  })
  onBeforeUnmount(() => unsubscribe?.())

  return { data, error, loading, updatedAt, refresh }
}

/**
 * Runs `task` now and then again after `interval()` ms, which is re-read every time (so it
 * can speed up while a deployment runs; null stops polling until the next trigger). Pauses while the browser tab is hidden, refreshes
 * as soon as it becomes visible or the connection comes back, and backs off on failures.
 */
export function usePolling(task: () => Promise<unknown>, interval: () => number | null): { trigger: () => Promise<void> } {
  let timer: ReturnType<typeof setTimeout> | null = null
  let stopped = false
  let inFlight = false
  let failures = 0

  function clear(): void {
    if (timer !== null) {
      clearTimeout(timer)
      timer = null
    }
  }

  function schedule(): void {
    clear()
    if (stopped || document.hidden) {
      return
    }
    const base = interval()
    if (base === null) {
      return
    }
    const delay = failures > 0 ? Math.min(base * 2 ** failures, 60000) : base
    timer = setTimeout(() => void trigger(), delay)
  }

  async function trigger(): Promise<void> {
    clear()
    if (stopped || inFlight) {
      return
    }
    inFlight = true
    try {
      const result = await task()
      failures = result === false ? failures + 1 : 0
    } catch {
      failures++
    } finally {
      inFlight = false
    }
    schedule()
  }

  const onVisibility = (): void => {
    if (document.hidden) {
      clear()
    } else {
      void trigger()
    }
  }
  let unsubscribe: (() => void) | null = null

  onMounted(() => {
    document.addEventListener('visibilitychange', onVisibility)
    unsubscribe = onReconnect(() => void trigger())
    void trigger()
  })
  onBeforeUnmount(() => {
    stopped = true
    clear()
    document.removeEventListener('visibilitychange', onVisibility)
    unsubscribe?.()
  })

  return { trigger }
}

/** Shows an error to the user, except where the UI already explains it (session overlay). */
export function reportError(e: unknown): void {
  const err = toApiError(e)
  if (err.code === 'session') {
    return
  }
  if (err.isConnection) {
    toasts.error(`${err.message} Nothing was changed.`, { tag: 'connection' })
    return
  }
  // Validation problems are also shown next to the fields, so this one need not stay.
  toasts.error(err.message, err.code === 'validation' ? { timeout: 6000 } : {})
}

/**
 * Wraps an action for a button or form: `busy` while it runs, ignores repeated clicks,
 * and reports failures as a toast.
 */
export function useTask<A extends unknown[], R>(fn: (...args: A) => Promise<R>): {
  busy: Ref<boolean>
  run: (...args: A) => Promise<R | undefined>
} {
  const busy = ref(false)
  async function run(...args: A): Promise<R | undefined> {
    if (busy.value) {
      return undefined
    }
    busy.value = true
    try {
      return await fn(...args)
    } catch (e) {
      reportError(e)
      return undefined
    } finally {
      busy.value = false
    }
  }
  return { busy, run }
}
