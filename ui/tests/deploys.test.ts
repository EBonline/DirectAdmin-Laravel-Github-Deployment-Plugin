import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'
import { defineComponent, h, nextTick, shallowRef } from 'vue'
import type { HistoryEntry, SiteStatus } from '../src/api/types'
import { useDeployWatch } from '../src/composables/deploys'
import { toastItems, toasts } from '../src/composables/toasts'

function entry(over: Partial<HistoryEntry>): HistoryEntry {
  return {
    id: '20261126120000',
    type: 'deploy',
    release: '20261126120000',
    trigger: 'manual',
    environment: 'testing',
    target_id: 'abc',
    ref: 'main',
    label: 'abc1234',
    sha: 'abc',
    message: 'Fix',
    author: '',
    url: null,
    status: 'running',
    activated: false,
    error: null,
    started_at: 1000,
    finished_at: null,
    ...over,
  }
}

function status(over: Partial<SiteStatus>): SiteStatus {
  return {
    site: { id: 's', hostname: 'example.test' } as SiteStatus['site'],
    current_id: null,
    current: null,
    last: null,
    remote: null,
    queued: null,
    running: null,
    update_available: false,
    docroot: null,
    last_poll: null,
    poll_error: null,
    ...over,
  }
}

describe('useDeployWatch', () => {
  beforeEach(() => toasts.clear())

  function setup() {
    const list = shallowRef<SiteStatus[] | null>(null)
    mount(
      defineComponent({
        setup() {
          useDeployWatch(() => list.value)
          return () => h('div')
        },
      }),
    )
    return list
  }

  it('announces a finished deployment once, with a link to its log', async () => {
    const list = setup()
    list.value = [status({ queued: { action: 'deploy', requested_at: 990 } })]
    await nextTick()
    list.value = [status({ running: { id: '20261126120000', started_at: 1000 }, last: entry({}) })]
    await nextTick()
    expect(toastItems).toHaveLength(0)
    list.value = [status({ last: entry({ status: 'success', activated: true, finished_at: 1072 }) })]
    await nextTick()
    expect(toastItems).toHaveLength(1)
    expect(toastItems[0]).toMatchObject({ tone: 'ok', message: 'Deployed abc1234 to example.test in 1m 12s.' })
    expect(toastItems[0]?.action?.to).toEqual({ name: 'log', query: { site: 's', id: '20261126120000' } })
    list.value = [status({ last: entry({ status: 'success', activated: true, finished_at: 1072 }) })]
    await nextTick()
    expect(toastItems).toHaveLength(1)
  })

  it('explains failures and stays quiet when a queued action is cancelled', async () => {
    const list = setup()
    const old = entry({ id: 'old', status: 'success', finished_at: 900 })
    list.value = [status({ queued: { action: 'deploy', requested_at: 990 }, last: old })]
    await nextTick()
    list.value = [status({ last: old })]
    await nextTick()
    expect(toastItems).toHaveLength(0)

    list.value = [status({ running: { id: 'new', started_at: 1000 }, last: entry({ id: 'new' }) })]
    await nextTick()
    list.value = [status({ last: entry({ id: 'new', status: 'failed', error: 'composer install failed', finished_at: 1010 }) })]
    await nextTick()
    expect(toastItems[0]).toMatchObject({ tone: 'err' })
    expect(toastItems[0]?.message).toContain('composer install failed')
    expect(toastItems[0]?.message).toContain('keeps running the previous release')
  })
})
