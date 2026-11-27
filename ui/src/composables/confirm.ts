import { reactive } from 'vue'

export interface ConfirmOptions {
  title: string
  message?: string
  confirmLabel?: string
  cancelLabel?: string
  tone?: 'primary' | 'danger'
  /** The user must type this exact text to enable the confirm button. */
  requireText?: string
}

export const confirmState = reactive({
  open: false,
  options: null as ConfirmOptions | null,
})

let resolver: ((ok: boolean) => void) | null = null

/** Opens the confirmation dialog; resolves true when the user confirms. */
export function confirm(options: ConfirmOptions): Promise<boolean> {
  resolver?.(false)
  return new Promise((resolve) => {
    resolver = resolve
    confirmState.options = options
    confirmState.open = true
  })
}

export function settleConfirm(ok: boolean): void {
  confirmState.open = false
  const r = resolver
  resolver = null
  r?.(ok)
}
