<script setup lang="ts">
import { computed, reactive } from 'vue'
import { RouterLink } from 'vue-router'
import type { Message, Overview, SiteStatus } from '../../api/types'
import AsyncButton from '../../components/AsyncButton.vue'
import EnvBadge from '../../components/EnvBadge.vue'
import ErrorState from '../../components/ErrorState.vue'
import Icon from '../../components/Icon.vue'
import PageHeader from '../../components/PageHeader.vue'
import RelativeTime from '../../components/RelativeTime.vue'
import Skeleton from '../../components/Skeleton.vue'
import StatusBadge from '../../components/StatusBadge.vue'
import ToggleSwitch from '../../components/ToggleSwitch.vue'
import UpdatedAt from '../../components/UpdatedAt.vue'
import { useNow } from '../../composables/clock'
import { isBusy, useDeployWatch } from '../../composables/deploys'
import { reportError, usePolling, useResource } from '../../composables/resource'
import { toasts } from '../../composables/toasts'
import { api } from '../../services'
import { clock, trim } from '../../utils/format'

const r = useResource(() => api().get<Overview>('overview'))
const sites = computed(() => r.data.value?.sites ?? [])
const anyBusy = computed(() => sites.value.some(isBusy))
const poll = usePolling(r.refresh, () => (anyBusy.value ? 3000 : 30000))
useDeployWatch(() => r.data.value?.sites)
const now = useNow()

// Auto-deploy switches update instantly and fall back if saving fails.
const autoOverride = reactive<Record<string, boolean>>({})
const autoBusy = reactive<Record<string, boolean>>({})

function autoOf(s: SiteStatus): boolean {
  return autoOverride[s.site.id] ?? s.site.auto_deploy
}

async function setAuto(s: SiteStatus, value: boolean): Promise<void> {
  const id = s.site.id
  autoOverride[id] = value
  autoBusy[id] = true
  try {
    const res = await api().post<Message>('set_auto', { site: id, enabled: value })
    toasts.success(res.message)
    await r.refresh()
  } catch (e) {
    reportError(e)
  } finally {
    delete autoOverride[id]
    delete autoBusy[id]
  }
}

async function deployNow(s: SiteStatus): Promise<void> {
  const res = await api().post<Message>('deploy', { site: s.site.id })
  toasts.success(`${s.site.hostname}: ${res.message}`)
  await poll.trigger()
}

function busyReason(s: SiteStatus): string | undefined {
  if (s.running) {
    return 'A deployment is running'
  }
  if (s.queued) {
    return 'An action is already queued'
  }
  return undefined
}
</script>

<template>
  <PageHeader title="Sites">
    <template #actions>
      <UpdatedAt :at="r.updatedAt.value" :loading="r.loading.value" />
      <RouterLink class="gd-btn gd-btn-primary" :to="{ name: 'edit' }"><Icon name="plus" />Add site</RouterLink>
    </template>
  </PageHeader>

  <Skeleton v-if="!r.data.value && !r.error.value" :lines="5" />
  <ErrorState v-else-if="!r.data.value && r.error.value" :error="r.error.value" :retry="r.refresh" />

  <template v-else-if="r.data.value">
    <section v-if="!r.data.value.has_token || sites.length === 0" class="gd-card">
      <h2>Get started</h2>
      <ol class="gd-steps">
        <li :class="r.data.value.has_token ? 'is-done' : 'is-current'">
          <span class="gd-step-mark"><Icon v-if="r.data.value.has_token" name="check" /><template v-else>1</template></span>
          <div class="gd-step-body">
            <div class="gd-step-title">Connect GitHub</div>
            <p v-if="r.data.value.has_token" class="gd-muted">Your GitHub token is saved.</p>
            <p v-else>
              Add a GitHub token so the plugin can see your repositories.
              <RouterLink :to="{ name: 'settings' }">Add a token in Settings →</RouterLink>
            </p>
          </div>
        </li>
        <li :class="sites.length > 0 ? 'is-done' : r.data.value.has_token ? 'is-current' : ''">
          <span class="gd-step-mark"><Icon v-if="sites.length > 0" name="check" /><template v-else>2</template></span>
          <div class="gd-step-body">
            <div class="gd-step-title">Add your first site</div>
            <p>
              Choose a domain and a repository. A <strong>testing</strong> site deploys every new commit on a branch; a
              <strong>production</strong> site deploys every new GitHub release.
              <RouterLink v-if="r.data.value.has_token" :to="{ name: 'edit' }">Add a site →</RouterLink>
            </p>
          </div>
        </li>
        <li>
          <span class="gd-step-mark">3</span>
          <div class="gd-step-body">
            <div class="gd-step-title">Deploy and point your domain to it</div>
            <p class="gd-muted">The site page guides you through the first deployment, the <code>.env</code> file and the document root.</p>
          </div>
        </li>
      </ol>
    </section>

    <div v-if="sites.length > 0" class="gd-card gd-flush">
      <table class="gd-table">
        <thead>
          <tr>
            <th>Site</th>
            <th class="gd-hide-sm">Live release</th>
            <th>Last deployment</th>
            <th class="gd-hide-sm">Auto-deploy</th>
            <th><span class="gd-sr">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in sites" :key="s.site.id" :class="{ 'is-busy': isBusy(s) }">
            <td>
              <RouterLink class="gd-strong" :to="{ name: 'site', query: { site: s.site.id } }">{{ s.site.hostname }}</RouterLink>
              <div style="margin-top: 4px"><EnvBadge :environment="s.site.environment" /></div>
              <div class="gd-sub">{{ s.site.repo }} · {{ s.site.source_label }}</div>
            </td>
            <td class="gd-hide-sm">
              <code v-if="s.current">{{ s.current.label }}</code>
              <code v-else-if="s.current_id">{{ s.current_id }}</code>
              <span v-else class="gd-muted">Not deployed yet</span>
              <div v-if="s.update_available && !autoOf(s) && !isBusy(s) && s.remote" class="gd-sub gd-warn-text">
                New: <code>{{ s.remote.label }}</code>
              </div>
            </td>
            <td>
              <StatusBadge :status="s.last?.status" :running="!!s.running" :queued="!!s.queued" />
              <span v-if="s.running" class="gd-timer" style="margin-left: 6px">{{ clock(now - s.running.started_at) }}</span>
              <div v-else-if="s.last" class="gd-sub">
                <code>{{ s.last.type === 'rollback' ? 'rollback' : s.last.label }}</code> ·
                <RelativeTime :ts="s.last.finished_at ?? s.last.started_at" />
              </div>
              <div v-if="!isBusy(s) && s.last?.error && s.last.status !== 'success'" class="gd-sub gd-err-text">{{ trim(s.last.error, 120) }}</div>
              <div v-if="s.poll_error" class="gd-sub gd-err-text"><Icon name="alert" /> GitHub: {{ trim(s.poll_error, 120) }}</div>
            </td>
            <td class="gd-hide-sm">
              <ToggleSwitch
                :model-value="autoOf(s)"
                :busy="autoBusy[s.site.id]"
                :label="`Automatic deployments for ${s.site.hostname}`"
                @update:model-value="setAuto(s, $event)"
              />
            </td>
            <td class="gd-right">
              <AsyncButton size="sm" icon="play" busy-label="Queueing…" :run="() => deployNow(s)" :disabled="isBusy(s)" :reason="busyReason(s)">
                Deploy now
              </AsyncButton>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <p class="gd-hint">
      Testing sites deploy every new commit on their branch; production sites deploy every new GitHub release.
      GitHub is checked every {{ r.data.value.poll_interval }} minutes, and this page updates by itself.
    </p>
  </template>
</template>
