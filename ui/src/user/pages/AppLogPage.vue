<script setup lang="ts">
// The Laravel logs of a site's live release (storage/logs/*.log), read-only: the tail of
// one file, following new lines while "Follow" is on.
import { computed, ref, shallowRef, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import type { AppLogChunk } from '../../api/types'
import Alert from '../../components/Alert.vue'
import AsyncButton from '../../components/AsyncButton.vue'
import ErrorState from '../../components/ErrorState.vue'
import LogView from '../../components/LogView.vue'
import PageHeader from '../../components/PageHeader.vue'
import Skeleton from '../../components/Skeleton.vue'
import ToggleSwitch from '../../components/ToggleSwitch.vue'
import { useNow } from '../../composables/clock'
import { useTail, type LogSource } from '../../composables/logstream'
import { api } from '../../services'
import { ago, bytes } from '../../utils/format'

const INTERVAL = 3000

const route = useRoute()
const router = useRouter()
const siteId = String(route.query.site ?? '')
const file = ref(String(route.query.file ?? ''))
const follow = ref(true)
const info = shallowRef<AppLogChunk | null>(null)
const now = useNow()

const tail = useTail(
  async (offset) => {
    if (file.value === '') {
      // Without a file the server answers with the tail of the newest one: start over.
      return { ...(await api().get<AppLogChunk>('app_log', { site: siteId })), reset: true }
    }
    return api().get<AppLogChunk>('app_log', { site: siteId, file: file.value, offset })
  },
  {
    interval: (): number | null => (follow.value || !tail.loaded.value ? INTERVAL : null),
    onChunk: (r) => {
      info.value = r
      if (file.value === '' && r.file) {
        file.value = r.file
        void router.replace({ query: { ...route.query, file: r.file } })
      }
    },
  },
)
const source: LogSource = { ...tail, running: follow }

watch(follow, (on) => {
  if (on) {
    void tail.refresh()
  }
})

const hostname = computed(() => info.value?.site.hostname ?? 'Site')
const missingFile = computed(() => tail.error.value?.code === 'no_log_file')

function choose(name: string): void {
  file.value = name
  const query = { ...route.query }
  if (name === '') {
    delete query.file
  } else {
    query.file = name
  }
  void router.replace({ query })
  void tail.restart()
}
</script>

<template>
  <PageHeader title="Laravel logs" :back="{ to: { name: 'site', query: { site: siteId } }, label: hostname }">
    <template v-if="info" #sub><code>{{ info.dir }}</code></template>
  </PageHeader>

  <ErrorState
    v-if="tail.error.value && (missingFile || !info)"
    :error="tail.error.value"
    :title="missingFile ? 'Log file not found' : undefined"
    :retry="missingFile ? undefined : tail.refresh"
  >
    <p v-if="missingFile"><button type="button" class="gd-btn" @click="choose('')">Show the newest log file</button></p>
    <p v-else><RouterLink :to="{ name: 'site', query: { site: siteId } }">Back to the site</RouterLink></p>
  </ErrorState>
  <Skeleton v-else-if="!info" :lines="10" />

  <template v-else>
    <Alert v-if="!info.exists" tone="info">
      There is no <code>storage/logs</code> directory in the live release yet. It appears after the first deployment, and
      Laravel writes its log files there.
    </Alert>
    <Alert v-else-if="info.files.length === 0" tone="info">
      There are no log files yet. Laravel creates them in this directory when it logs something.
    </Alert>
    <Alert v-else-if="!info.file" tone="warn">
      Your account cannot read any of the log files in this directory. They were probably written by another user, such as
      the web server; change their owner or permissions over SSH.
    </Alert>

    <template v-else>
      <div class="gd-toolbar">
        <select :value="file" aria-label="Log file" style="width: auto; max-width: 100%" @change="choose(($event.target as HTMLSelectElement).value)">
          <option v-for="f in info.files" :key="f.name" :value="f.name" :disabled="!f.readable">
            {{ f.name }} · {{ bytes(f.size) }} · {{ f.readable ? ago(f.modified_at, now) : 'not readable' }}
          </option>
        </select>
        <div class="gd-btn-row">
          <ToggleSwitch v-model="follow" label="Follow new lines" on-text="Following" off-text="Paused" />
          <AsyncButton v-if="!follow" size="sm" icon="refresh" busy-label="Refreshing…" :run="tail.refresh">Refresh</AsyncButton>
        </div>
      </div>
      <LogView
        :stream="source"
        label="Laravel log"
        :filename="`${hostname}-${file}`"
        empty="This log file is empty."
        :interval="INTERVAL"
      />
    </template>
  </template>
</template>
