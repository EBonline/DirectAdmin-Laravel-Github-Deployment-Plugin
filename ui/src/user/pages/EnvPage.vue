<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { ApiError } from '../../api/client'
import type { EnvFile, Message } from '../../api/types'
import Alert from '../../components/Alert.vue'
import AsyncButton from '../../components/AsyncButton.vue'
import CodeEditor from '../../components/CodeEditor.vue'
import ErrorState from '../../components/ErrorState.vue'
import PageHeader from '../../components/PageHeader.vue'
import Skeleton from '../../components/Skeleton.vue'
import { useResource, useTask } from '../../composables/resource'
import { toasts } from '../../composables/toasts'
import { useSaveShortcut, useUnsavedGuard } from '../../composables/unsaved'
import { api } from '../../services'
import { utf8Length } from '../../utils/validate'

const route = useRoute()
const siteId = String(route.query.site ?? '')
const r = useResource(() => api().get<EnvFile>('env', { site: siteId }))
void r.refresh()

const content = ref('')
const saved = ref<string | null>(null)
const justSaved = ref(false)
const dirty = computed(() => saved.value !== null && content.value !== saved.value)
const tooLarge = computed(() => !!r.data.value && utf8Length(content.value) > r.data.value.max_bytes)

watch(r.data, (d) => {
  if (d) {
    content.value = d.content
    saved.value = d.content
  }
})
watch(content, () => {
  if (dirty.value) {
    justSaved.value = false
  }
})

useUnsavedGuard(() => dirty.value)

const save = useTask(async () => {
  if (tooLarge.value) {
    throw new ApiError('validation', 'The file is too large to save (max 64 KB).')
  }
  const value = content.value
  const res = await api().post<Message>('save_env', { site: siteId, content: value })
  saved.value = value
  justSaved.value = true
  toasts.success('.env saved.')
  return res
})
useSaveShortcut(() => {
  if (dirty.value) {
    void save.run()
  }
})

async function deployNow(): Promise<void> {
  const res = await api().post<Message>('deploy', { site: siteId })
  toasts.success(res.message, { action: { label: 'Follow it on the site page', to: { name: 'site', query: { site: siteId } } } })
  justSaved.value = false
}
</script>

<template>
  <PageHeader title="Environment file" :back="{ to: { name: 'site', query: { site: siteId } }, label: r.data.value?.site.hostname ?? 'Site' }">
    <template v-if="r.data.value" #sub><code>{{ r.data.value.path }}</code></template>
  </PageHeader>

  <Skeleton v-if="!r.data.value && !r.error.value" :lines="10" />
  <ErrorState v-else-if="!r.data.value && r.error.value" :error="r.error.value" :retry="r.refresh" />

  <template v-else-if="r.data.value">
    <Alert v-if="!r.data.value.shared" tone="warn">
      <code>.env</code> is not one of this site's shared paths, so releases do not use this file.
      <RouterLink :to="{ name: 'edit', query: { site: siteId } }">Add it to the shared paths</RouterLink>.
    </Alert>
    <Alert v-if="!r.data.value.valid_utf8" tone="warn">
      This file contains bytes that are not valid UTF-8. Saving it here may change them; edit it over SSH instead if that matters.
    </Alert>
    <Alert v-if="justSaved" tone="ok">
      Saved. Laravel caches its configuration during deployment, so deploy again for the changes to take effect.
      <div style="margin-top: 6px"><AsyncButton size="sm" icon="play" busy-label="Queueing…" :run="deployNow">Deploy now</AsyncButton></div>
    </Alert>

    <form class="gd-card" novalidate @submit.prevent="save.run()">
      <p class="gd-hint" style="margin-top: 0">
        <template v-if="!r.data.value.exists">This file does not exist yet. It is created from <code>.env.example</code> on the first deployment, or when you save here. </template>
        Readable only by your account and the web server. Lines starting with <code>#</code> are comments.
      </p>
      <CodeEditor id="gd-env" v-model="content" :rows="24" label=".env file" :max-bytes="r.data.value.max_bytes" />
      <div class="gd-actions">
        <AsyncButton type="submit" variant="primary" :busy="save.busy.value" busy-label="Saving…" :disabled="!dirty || tooLarge" :reason="tooLarge ? 'The file is too large' : 'No changes to save'">
          Save
        </AsyncButton>
        <button v-if="dirty" type="button" class="gd-btn" @click="content = saved ?? ''">Undo changes</button>
        <span v-if="dirty" class="gd-muted gd-small">Unsaved changes</span>
      </div>
    </form>
  </template>
</template>
