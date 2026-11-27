import { computed, ref, type ComputedRef } from 'vue'

// One shared ticking clock, corrected for the difference between the browser's clock and
// the server's, so "2 min ago" and running timers are right even when the PC clock is off.

const offset = ref(0)
const local = ref(Date.now() / 1000)
let timer: ReturnType<typeof setInterval> | null = null

function ensureTicking(): void {
  if (timer === null && typeof window !== 'undefined') {
    timer = setInterval(() => {
      local.value = Date.now() / 1000
    }, 1000)
  }
}

export function setServerTime(serverTime: number): void {
  offset.value = serverTime - Date.now() / 1000
}

/** Current server time in unix seconds. */
export function serverNow(): number {
  return Date.now() / 1000 + offset.value
}

const now = computed(() => local.value + offset.value)

/** Reactive server time (unix seconds) that updates every second. */
export function useNow(): ComputedRef<number> {
  ensureTicking()
  return now
}
