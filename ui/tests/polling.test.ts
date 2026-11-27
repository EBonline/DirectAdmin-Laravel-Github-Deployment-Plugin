import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { usePolling } from '../src/composables/resource'

function setHidden(hidden: boolean): void {
  Object.defineProperty(document, 'hidden', { configurable: true, get: () => hidden })
  document.dispatchEvent(new Event('visibilitychange'))
}

describe('usePolling', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    setHidden(false)
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  it('runs at once, then at an interval that can change, and pauses while the tab is hidden', async () => {
    const task = vi.fn(async () => true)
    const fast = ref(false)
    const wrapper = mount(
      defineComponent({
        setup() {
          usePolling(task, () => (fast.value ? 1000 : 10000))
          return () => h('div')
        },
      }),
    )
    await vi.advanceTimersByTimeAsync(0)
    expect(task).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(10000)
    expect(task).toHaveBeenCalledTimes(2)

    fast.value = true // a deployment started: the next interval is shorter
    await vi.advanceTimersByTimeAsync(10000)
    expect(task).toHaveBeenCalledTimes(3)
    await vi.advanceTimersByTimeAsync(1000)
    expect(task).toHaveBeenCalledTimes(4)

    setHidden(true)
    await vi.advanceTimersByTimeAsync(5000)
    expect(task).toHaveBeenCalledTimes(4)

    setHidden(false) // coming back refreshes immediately
    await vi.advanceTimersByTimeAsync(0)
    expect(task).toHaveBeenCalledTimes(5)

    wrapper.unmount()
    await vi.advanceTimersByTimeAsync(60000)
    expect(task).toHaveBeenCalledTimes(5)
  })

  it('backs off after failures', async () => {
    const task = vi.fn(async () => false)
    mount(
      defineComponent({
        setup() {
          usePolling(task, () => 1000)
          return () => h('div')
        },
      }),
    )
    await vi.advanceTimersByTimeAsync(0)
    expect(task).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(1000)
    expect(task).toHaveBeenCalledTimes(1) // waits 2s after the first failure
    await vi.advanceTimersByTimeAsync(1000)
    expect(task).toHaveBeenCalledTimes(2)
  })
})
