<script setup lang="ts">
import { computed, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import Alert from '../../components/Alert.vue'
import ErrorState from '../../components/ErrorState.vue'
import LogView from '../../components/LogView.vue'
import PageHeader from '../../components/PageHeader.vue'
import StatusBadge from '../../components/StatusBadge.vue'
import { useNow } from '../../composables/clock'
import { announceFinished, setTabStatus } from '../../composables/deploys'
import { useLogStream } from '../../composables/logstream'
import { capitalize, clock, dateTime, duration } from '../../utils/format'

const route = useRoute()
const siteId = String(route.query.site ?? '')
const logId = String(route.query.id ?? '')
const stream = useLogStream(siteId, logId)
const entry = stream.entry
const now = useNow()

const title = computed(() => {
  const e = entry.value
  if (!e) {
    return 'Deployment log'
  }
  return e.type === 'rollback' ? `Rollback to release ${e.release}` : `Deployment of ${e.label}`
})
const hostname = computed(() => stream.site.value?.hostname ?? 'Site')
const finished = computed(() => stream.loaded.value && !stream.running.value && entry.value !== null && entry.value.status !== 'running')

// Tell the user when the deployment they are watching finishes.
watch(stream.running, (running, was) => {
  if (running) {
    setTabStatus('● Deploying…')
  } else if (was && entry.value) {
    const result = announceFinished(hostname.value, siteId, entry.value, false)
    setTabStatus(result === 'ok' ? '✓ Deployed' : '✗ Failed')
  }
})
</script>

<template>
  <PageHeader :title="title" :back="{ to: { name: 'site', query: { site: siteId } }, label: hostname }">
    <template #badges>
      <StatusBadge v-if="entry || stream.running.value" :status="entry?.status" :running="stream.running.value" />
    </template>
    <template v-if="entry" #sub>
      Started {{ dateTime(entry.started_at) }} · {{ capitalize(entry.trigger) }}
      <template v-if="stream.running.value"> · running for <span class="gd-timer">{{ clock(now - entry.started_at) }}</span></template>
      <template v-else-if="entry.finished_at"> · took {{ duration(entry.finished_at - entry.started_at) }}</template>
      <template v-if="entry.message && entry.type !== 'rollback'"> · {{ entry.message }}</template>
    </template>
  </PageHeader>

  <ErrorState v-if="stream.error.value && !stream.text.value && !entry" :error="stream.error.value" :retry="stream.refresh">
    <p><RouterLink :to="{ name: 'site', query: { site: siteId } }">Back to the site</RouterLink></p>
  </ErrorState>

  <template v-else>
    <template v-if="finished && entry">
      <Alert v-if="entry.status === 'success'" tone="ok">
        <strong>{{ entry.type === 'rollback' ? 'Rolled back.' : 'Deployed successfully.' }}</strong>
        Release <code>{{ entry.release }}</code> is live.
        <RouterLink :to="{ name: 'site', query: { site: siteId } }">Back to {{ hostname }}</RouterLink>
      </Alert>
      <Alert v-else-if="entry.status === 'failed_after_activation'" tone="warn">
        <strong>Failed after the release went live.</strong> {{ entry.error }}
        The new release is serving visitors; check the steps after <code>$ACTIVATE_RELEASE()</code> in the log below.
      </Alert>
      <Alert v-else tone="err">
        <strong>{{ entry.type === 'rollback' ? 'Rollback failed.' : 'Deployment failed.' }}</strong> {{ entry.error }}
        <template v-if="entry.type === 'deploy'">
          The site keeps running the previous release. Fix the problem (the log below shows where it stopped), then push a new
          {{ entry.environment === 'production' ? 'release' : 'commit' }} or deploy again.
        </template>
      </Alert>
    </template>
    <Alert v-else-if="stream.running.value" tone="run" :icon="null">
      <span class="gd-inline-flex"><span class="gd-spinner" aria-hidden="true"></span><strong>Deploying.</strong></span>
      The site keeps serving the current release until this one is ready. You can leave this page; the deployment continues.
    </Alert>

    <LogView :site-id="siteId" :log-id="logId" :stream="stream" />
  </template>
</template>
