import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import type { TailChunk } from '../src/api/types'
import { useTail } from '../src/composables/logstream'

function chunk(over: Partial<TailChunk>): TailChunk {
  return { chunk: '', offset: 0, more: false, truncated: false, reset: false, size: 0, ...over }
}

function mountTail(load: (offset: number | undefined) => Promise<TailChunk>, interval: number | null = 1000) {
  let tail!: ReturnType<typeof useTail<TailChunk>>
  mount(
    defineComponent({
      setup() {
        tail = useTail(load, { interval: () => interval })
        return () => h('div')
      },
    }),
  )
  return () => tail
}

describe('useTail', () => {
  beforeEach(() => {
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => false })
    vi.useFakeTimers()
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  it('loads the tail, appends what was added, and starts over when the file was truncated', async () => {
    const answers = [
      chunk({ chunk: 'a\nb\n', offset: 4, truncated: true }),
      chunk({ chunk: 'c\n', offset: 6 }),
      chunk({ chunk: 'new\n', offset: 4, reset: true }),
    ]
    const load = vi.fn(async () => answers.shift() ?? chunk({ offset: 4 }))
    const tail = mountTail(load)

    await vi.advanceTimersByTimeAsync(0)
    expect(load).toHaveBeenLastCalledWith(undefined)
    expect(tail().text.value).toBe('a\nb\n')
    expect(tail().truncated.value).toBe(true)

    await vi.advanceTimersByTimeAsync(1000)
    expect(load).toHaveBeenLastCalledWith(4)
    expect(tail().text.value).toBe('a\nb\nc\n')

    await vi.advanceTimersByTimeAsync(1000)
    expect(load).toHaveBeenLastCalledWith(6)
    expect(tail().text.value).toBe('new\n')
    expect(tail().truncated.value).toBe(false)
  })

  it('restart() loads the tail again, even with polling paused', async () => {
    const load = vi.fn(async (offset: number | undefined) => chunk({ chunk: offset === undefined ? 'tail\n' : '', offset: 5 }))
    const tail = mountTail(load, null)

    await vi.advanceTimersByTimeAsync(0)
    expect(tail().text.value).toBe('tail\n')
    await vi.advanceTimersByTimeAsync(10000)
    expect(load).toHaveBeenCalledTimes(1) // paused

    await tail().restart()
    expect(load).toHaveBeenCalledTimes(2)
    expect(load).toHaveBeenLastCalledWith(undefined)
    expect(tail().text.value).toBe('tail\n')
  })

  it('a restart while a request is under way discards its answer and loads again', async () => {
    let release!: () => void
    const answers = [
      () => new Promise<TailChunk>((resolve) => (release = () => resolve(chunk({ chunk: 'old file\n', offset: 9 })))),
      async () => chunk({ chunk: 'new file\n', offset: 9 }),
    ]
    const load = vi.fn((_offset: number | undefined) => answers.shift()!())
    const tail = mountTail(load, null)

    await vi.advanceTimersByTimeAsync(0)
    void tail().restart()
    release()
    await vi.advanceTimersByTimeAsync(0)
    expect(load).toHaveBeenCalledTimes(2)
    expect(load).toHaveBeenLastCalledWith(undefined)
    expect(tail().text.value).toBe('new file\n')
  })
})
