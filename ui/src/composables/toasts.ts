import { reactive } from 'vue'
import type { RouteLocationRaw } from 'vue-router'

export type ToastTone = 'ok' | 'err' | 'info' | 'warn'

export interface ToastAction {
  label: string
  to?: RouteLocationRaw
  onClick?: () => void
}

export interface Toast {
  id: number
  tone: ToastTone
  message: string
  action?: ToastAction
  /** ms before it disappears by itself; 0 = stays until dismissed. */
  timeout: number
  /** Groups toasts that can be cleared together, e.g. 'connection'. */
  tag?: string
}

interface ToastOptions {
  action?: ToastAction
  timeout?: number
  tag?: string
}

const MAX = 5
let nextId = 1

export const toastItems = reactive<Toast[]>([])

function show(tone: ToastTone, message: string, opts: ToastOptions = {}): number {
  const id = nextId++
  // Errors stay until the user dismisses them: they usually need reading.
  const timeout = opts.timeout ?? (tone === 'err' ? 0 : tone === 'warn' ? 10000 : 6000)
  // The same message twice in a row (e.g. repeated failures) replaces the earlier one.
  const dup = toastItems.findIndex((t) => t.message === message && t.tone === tone)
  if (dup !== -1) {
    toastItems.splice(dup, 1)
  }
  toastItems.push({ id, tone, message, action: opts.action, timeout, tag: opts.tag })
  while (toastItems.length > MAX) {
    toastItems.shift()
  }
  return id
}

export const toasts = {
  success: (message: string, opts?: ToastOptions) => show('ok', message, opts),
  error: (message: string, opts?: ToastOptions) => show('err', message, opts),
  info: (message: string, opts?: ToastOptions) => show('info', message, opts),
  warn: (message: string, opts?: ToastOptions) => show('warn', message, opts),
  dismiss(id: number): void {
    const i = toastItems.findIndex((t) => t.id === id)
    if (i !== -1) {
      toastItems.splice(i, 1)
    }
  },
  dismissTag(tag: string): void {
    for (let i = toastItems.length - 1; i >= 0; i--) {
      if (toastItems[i]?.tag === tag) {
        toastItems.splice(i, 1)
      }
    }
  },
  clear(): void {
    toastItems.splice(0)
  },
}
