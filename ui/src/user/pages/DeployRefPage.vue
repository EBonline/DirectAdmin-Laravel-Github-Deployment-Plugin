<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { ApiError, toApiError } from '../../api/client'
import type { Message, Refs, Target } from '../../api/types'
import Alert from '../../components/Alert.vue'
import AsyncButton from '../../components/AsyncButton.vue'
import FormField from '../../components/FormField.vue'
import Icon from '../../components/Icon.vue'
import PageHeader from '../../components/PageHeader.vue'
import RelativeTime from '../../components/RelativeTime.vue'
import { confirm } from '../../composables/confirm'
import { useResource } from '../../composables/resource'
import { toasts } from '../../composables/toasts'
import { api } from '../../services'
import { trim } from '../../utils/format'
import { isBranch, isSha } from '../../utils/validate'

const route = useRoute()
const router = useRouter()
const siteId = String(route.query.site ?? '')

const r = useResource(() => api().get<Refs>('refs', { site: siteId }))
void r.refresh()

// The page title must not wait for GitHub, so fall back to the query until it answers.
const kind = computed(() => r.data.value?.kind ?? 'commit')
const hostname = computed(() => r.data.value?.site.hostname ?? 'Site')
const isProd = computed(() => kind.value === 'release')

async function deploy(ref: string, label: string, message: string): Promise<void> {
  const ok = await confirm({
    title: `Deploy ${label} to ${hostname.value}?`,
    message:
      `${isProd.value ? 'Release' : 'Commit'} ${label} is built as a new release and goes live when every step has succeeded. ` +
      (r.data.value?.site.auto_deploy
        ? `Automatic deployments stay on, so the next new ${kind.value} on GitHub is deployed again.`
        : ''),
    confirmLabel: `Deploy ${label}`,
  })
  if (!ok) {
    return
  }
  const res = await api().post<Message>('deploy_ref', { site: siteId, ref, message })
  toasts.success(res.message)
  await router.push({ name: 'site', query: { site: siteId } })
}

function deployItem(it: Target): Promise<void> {
  return deploy(isProd.value ? it.ref : (it.sha ?? it.id), it.label, trim(it.message, 200))
}

const manualRef = ref('')
const manualError = ref('')

async function deployManual(): Promise<void> {
  const value = isProd.value ? manualRef.value.trim() : manualRef.value.trim().toLowerCase()
  const valid = isProd.value ? isBranch(value) : isSha(value)
  if (!valid) {
    manualError.value = isProd.value ? 'Enter a valid tag name, for example v1.2.3.' : 'Enter a commit sha (7–40 hexadecimal characters).'
    throw new ApiError('validation', manualError.value)
  }
  manualError.value = ''
  try {
    await deploy(value, isProd.value ? value : value.slice(0, 7), '')
  } catch (e) {
    const err = toApiError(e)
    if (err.fields.ref) {
      manualError.value = err.fields.ref
    }
    throw err
  }
}
</script>

<template>
  <PageHeader
    :title="r.data.value ? `Deploy a specific ${kind}` : 'Deploy a specific version'"
    :back="{ to: { name: 'site', query: { site: siteId } }, label: hostname }"
  />

  <section class="gd-card gd-flush">
    <div class="gd-card-head">
      <h2 v-if="r.data.value">{{ isProd ? `Releases of ${r.data.value.site.repo}` : `Recent commits on ${r.data.value.site.branch}` }}</h2>
      <h2 v-else>From GitHub</h2>
      <AsyncButton v-if="r.data.value || r.error.value" size="sm" icon="refresh" busy-label="Loading…" :busy="r.loading.value" :run="r.refresh">Reload</AsyncButton>
    </div>
    <div v-if="r.loading.value && !r.data.value" class="gd-pad gd-muted" role="status">
      <span class="gd-spinner" aria-hidden="true"></span> Asking GitHub for the latest versions…
    </div>
    <div v-else-if="r.error.value && !r.data.value" class="gd-pad">
      <Alert tone="err">{{ r.error.value.message }} You can still deploy a version by name below.</Alert>
    </div>
    <p v-else-if="r.data.value && r.data.value.items.length === 0" class="gd-pad gd-muted">
      {{ isProd ? 'This repository has no published releases yet.' : 'No commits found on this branch.' }}
    </p>
    <table v-else-if="r.data.value" class="gd-table">
      <thead>
        <tr>
          <th>{{ isProd ? 'Release' : 'Commit' }}</th>
          <th>Description</th>
          <th class="gd-hide-sm">Date</th>
          <th><span class="gd-sr">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="it in r.data.value.items" :key="it.id" :class="{ 'is-current': it.id === r.data.value.current_target }">
          <td class="gd-nowrap">
            <a v-if="it.url" :href="it.url" target="_blank" rel="noopener"><code>{{ it.label }}</code></a>
            <code v-else>{{ it.label }}</code>
            <span v-if="it.prerelease" class="gd-badge gd-badge-warn" style="margin-left: 6px">Pre-release</span>
            <span v-if="it.id === r.data.value.current_target" class="gd-badge gd-badge-ok" style="margin-left: 6px">Live</span>
          </td>
          <td>{{ trim(it.message, 90) }} <span class="gd-muted">{{ it.author }}</span></td>
          <td class="gd-hide-sm gd-nowrap"><RelativeTime :ts="it.date" never="—" /></td>
          <td class="gd-right">
            <AsyncButton
              size="sm"
              icon="play"
              busy-label="Queueing…"
              :run="() => deployItem(it)"
              :disabled="it.id === r.data.value.current_target"
              reason="This version is already live"
            >
              Deploy
            </AsyncButton>
          </td>
        </tr>
      </tbody>
    </table>
  </section>

  <form class="gd-card" novalidate @submit.prevent>
    <h2>Deploy another {{ isProd ? 'tag' : 'commit' }}</h2>
    <div class="gd-row">
      <FormField id="gd-ref" :label="isProd ? 'Tag name' : 'Commit sha'" :error="manualError" style="flex: 1; min-width: 220px; max-width: 360px">
        <input id="gd-ref" v-model="manualRef" type="text" :placeholder="isProd ? 'v1.2.3' : 'a1b2c3d'" autocomplete="off" spellcheck="false" />
      </FormField>
      <div style="padding-top: 26px">
        <AsyncButton type="submit" variant="primary" icon="play" busy-label="Queueing…" :run="deployManual" :disabled="manualRef.trim() === ''" reason="Enter a name first">
          Deploy
        </AsyncButton>
      </div>
    </div>
    <p class="gd-hint">
      Deploying an older {{ kind }} does not pause automatic deployments: the next new {{ kind }} on GitHub is still deployed.
      <RouterLink :to="{ name: 'edit', query: { site: siteId } }">Turn off automatic deployments</RouterLink> to stay on this version.
    </p>
    <p class="gd-hint"><Icon name="info" /> Rolling back to a release that is still on disk is faster: use <strong>Roll back</strong> on the site page.</p>
  </form>
</template>
