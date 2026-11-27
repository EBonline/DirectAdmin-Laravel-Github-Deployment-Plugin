<script setup lang="ts">
// What is happening with a site right now: a queued action waiting for the scheduler, or a
// running deployment with a timer and the live end of its log.
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import type { SiteStatus } from '../api/types'
import { useNow } from '../composables/clock'
import { toasts } from '../composables/toasts'
import { api } from '../services'
import { clock, duration, entryTitle, shortSha } from '../utils/format'
import Alert from './Alert.vue'
import AsyncButton from './AsyncButton.vue'
import Icon from './Icon.vue'
import LogTail from './LogTail.vue'

const props = defineProps<{ status: SiteStatus; deployTimeout?: number }>()
const emit = defineEmits<{ changed: [] }>()

/** A queued action that has waited this long suggests the scheduler (cron) is not running. */
const SLOW_QUEUE = 180

const now = useNow()
const site = computed(() => props.status.site)
const queuedFor = computed(() => (props.status.queued ? now.value - props.status.queued.requested_at : 0))
const runningFor = computed(() => (props.status.running ? now.value - props.status.running.started_at : 0))
const runningEntry = computed(() => {
  const last = props.status.last
  return props.status.running && last?.id === props.status.running.id ? last : null
})
const isRollback = computed(() => props.status.queued?.action === 'rollback' || runningEntry.value?.type === 'rollback')

const queuedLabel = computed(() => {
  const q = props.status.queued
  if (!q) {
    return ''
  }
  switch (q.action) {
    case 'rollback':
      return `Roll back to release ${q.release ?? ''}`
    case 'deploy_ref':
      return `Deploy ${q.ref && q.ref.length === 40 ? shortSha(q.ref) : (q.ref ?? '')}`
    default:
      return site.value.environment === 'production' ? 'Deploy the latest release' : `Deploy the latest commit on ${site.value.branch}`
  }
})

async function cancel(): Promise<void> {
  const r = await api().post<{ cancelled: boolean; message: string }>('cancel_queue', { site: site.value.id })
  if (r.cancelled) {
    toasts.success(r.message)
  } else {
    toasts.info(r.message)
  }
  emit('changed')
}
</script>

<template>
  <section v-if="status.running" class="gd-card gd-progress" aria-live="polite">
    <div class="gd-progress-head">
      <div class="gd-progress-title">
        <span class="gd-dot is-live" aria-hidden="true"></span>
        {{ isRollback ? 'Rolling back' : 'Deploying' }} {{ runningEntry ? entryTitle(runningEntry) : '' }}
      </div>
      <span class="gd-timer" title="Time since the deployment started">{{ clock(runningFor) }}</span>
    </div>
    <ol class="gd-phases">
      <li class="is-done"><Icon name="check" />Queued</li>
      <li class="is-active"><span class="gd-spinner" aria-hidden="true"></span>{{ isRollback ? 'Switching' : 'Building the new release' }}</li>
      <li>Live</li>
    </ol>
    <LogTail :key="status.running.id" :site-id="site.id" :log-id="status.running.id" />
    <div class="gd-toolbar" style="margin: 10px 0 0">
      <span class="gd-hint" style="margin: 0">
        The current release keeps serving visitors until the new one is ready. If a step fails, the new release is thrown away.
        <template v-if="deployTimeout">Stops after {{ deployTimeout }} min.</template>
      </span>
      <RouterLink :to="{ name: 'log', query: { site: site.id, id: status.running.id } }">Open full log →</RouterLink>
    </div>
  </section>

  <section v-else-if="status.queued" class="gd-card gd-progress is-queued" aria-live="polite">
    <div class="gd-progress-head">
      <div class="gd-progress-title"><span class="gd-spinner" aria-hidden="true"></span>Queued: {{ queuedLabel }}</div>
      <span class="gd-timer" title="Time spent waiting">{{ clock(queuedFor) }}</span>
    </div>
    <ol class="gd-phases">
      <li class="is-active">Waiting for the scheduler</li>
      <li>{{ isRollback ? 'Switching' : 'Building the new release' }}</li>
      <li>Live</li>
    </ol>
    <p>The scheduler checks for work every minute, so this usually starts within a minute. You can leave this page; it keeps going.</p>
    <Alert v-if="queuedFor > SLOW_QUEUE" tone="warn">
      This has been waiting for {{ duration(queuedFor) }}, which is longer than usual. The plugin's scheduler may not be running.
      If it stays queued, contact your hosting administrator.
    </Alert>
    <AsyncButton size="sm" :run="cancel" busy-label="Cancelling…" icon="x">Cancel</AsyncButton>
  </section>
</template>
