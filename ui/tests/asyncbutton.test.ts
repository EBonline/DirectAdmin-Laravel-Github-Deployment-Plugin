import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import AsyncButton from '../src/components/AsyncButton.vue'
import { toastItems, toasts } from '../src/composables/toasts'

describe('AsyncButton', () => {
  it('shows a busy label and ignores clicks while the action runs', async () => {
    let finish: () => void = () => {}
    const run = vi.fn(() => new Promise<void>((resolve) => (finish = resolve)))
    const w = mount(AsyncButton, { props: { run, busyLabel: 'Saving…' }, slots: { default: 'Save' } })
    await w.trigger('click')
    await w.trigger('click')
    expect(run).toHaveBeenCalledTimes(1)
    expect(w.text()).toContain('Saving…')
    expect(w.attributes('aria-busy')).toBe('true')
    finish()
    await vi.waitFor(() => expect(w.text()).toContain('Save'))
    expect(w.attributes('aria-busy')).toBeUndefined()
  })

  it('reports a failure as an error toast', async () => {
    toasts.clear()
    const w = mount(AsyncButton, { props: { run: () => Promise.reject(new Error('Nope')) }, slots: { default: 'Go' } })
    await w.trigger('click')
    await vi.waitFor(() => expect(toastItems.some((t) => t.tone === 'err' && t.message === 'Nope')).toBe(true))
  })

  it('explains why it is disabled', () => {
    const w = mount(AsyncButton, { props: { disabled: true, reason: 'A deployment is running' }, slots: { default: 'Deploy' } })
    expect(w.attributes('disabled')).toBeDefined()
    expect(w.attributes('title')).toBe('A deployment is running')
    expect(w.text()).toContain('A deployment is running')
  })
})
