<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { toApiError } from '../api/client'
import type { AccessOverride, AdminOverview, AdminSettings } from '../api/types'
import Alert from '../components/Alert.vue'
import AppShell from '../components/AppShell.vue'
import AsyncButton from '../components/AsyncButton.vue'
import CopyField from '../components/CopyField.vue'
import EnvBadge from '../components/EnvBadge.vue'
import ErrorState from '../components/ErrorState.vue'
import FormField from '../components/FormField.vue'
import Icon from '../components/Icon.vue'
import RelativeTime from '../components/RelativeTime.vue'
import Skeleton from '../components/Skeleton.vue'
import StatusBadge from '../components/StatusBadge.vue'
import UpdatedAt from '../components/UpdatedAt.vue'
import { useNow } from '../composables/clock'
import { confirm } from '../composables/confirm'
import { usePolling, useResource, useTask } from '../composables/resource'
import { toasts } from '../composables/toasts'
import { useBeforeUnload } from '../composables/unsaved'
import { api } from '../services'
import { trim } from '../utils/format'

const r = useResource(() => api().get<AdminOverview>('overview'))
usePolling(r.refresh, () => 30000)
const d = computed(() => r.data.value)
const now = useNow()

// ---------------------------------------------------------------- status

const lastRun = computed(() => d.value?.dispatcher?.last_run ?? null)
const cronHealthy = computed(() => !!lastRun.value && now.value - lastRun.value <= (d.value?.cron_stale_after ?? 300))
const allSites = computed(() => (d.value?.users ?? []).flatMap((u) => u.sites.map((s) => ({ user: u.user, updated_at: u.updated_at, ...s }))))
const userErrors = computed(() => (d.value?.users ?? []).filter((u) => u.error))
const failing = computed(() => allSites.value.filter((s) => s.last_status === 'failed' || s.last_status === 'failed_after_activation' || s.poll_error))
const diagProblems = computed(() => (d.value?.diagnostics ?? []).filter((x) => !x.ok && !x.optional).length)
/** Why the settings and overrides are disabled ('' when they can be changed). */
const readOnlyReason = computed(() => {
  const x = d.value
  if (!x || x.writable) {
    return ''
  }
  return x.permission_fix === 'main_admin' && x.data_owner ? `Only ${x.data_owner} can change this` : 'The data directory is not writable'
})

const search = ref('')
const filter = ref<'all' | 'problems'>('all')
const shownSites = computed(() => {
  const q = search.value.trim().toLowerCase()
  return allSites.value.filter((s) => {
    if (filter.value === 'problems' && !failing.value.includes(s)) {
      return false
    }
    return q === '' || [s.user, s.hostname, s.repo].some((v) => v?.toLowerCase().includes(q))
  })
})

// -------------------------------------------------------------- settings

const form = reactive<AdminSettings>({
  poll_interval: 5,
  keep_releases: 5,
  max_keep_releases: 20,
  deploy_timeout: 15,
  reload_fpm: true,
  max_parallel: 4,
  access_policy: 'ssh',
  node_path: '',
  npm_path: '',
})
const savedSettings = ref<string | null>(null)
const errors = reactive<Record<string, string>>({})
const dirty = computed(() => savedSettings.value !== null && JSON.stringify(form) !== savedSettings.value)
useBeforeUnload(() => dirty.value)

// Background refreshes must not overwrite what the admin is typing.
watch(d, (data) => {
  if (data && !dirty.value) {
    Object.assign(form, data.settings)
    savedSettings.value = JSON.stringify(form)
  }
})

const RANGES: Record<string, [number, number]> = {
  poll_interval: [1, 60],
  deploy_timeout: [1, 180],
  keep_releases: [1, 50],
  max_keep_releases: [1, 50],
  max_parallel: [1, 32],
}

function validate(): boolean {
  for (const k of Object.keys(errors)) {
    delete errors[k]
  }
  for (const [key, [min, max]] of Object.entries(RANGES)) {
    const v = Number(form[key as keyof AdminSettings])
    if (!Number.isInteger(v) || v < min || v > max) {
      errors[key] = `Must be a number between ${min} and ${max}.`
    }
  }
  if (!errors.keep_releases && !errors.max_keep_releases && form.keep_releases > form.max_keep_releases) {
    errors.keep_releases = 'Cannot be higher than the maximum.'
  }
  for (const key of ['node_path', 'npm_path'] as const) {
    form[key] = form[key].trim()
    if (form[key] !== '' && (!form[key].startsWith('/') || /(^|\/)\.\.?(\/|$)/.test(form[key]))) {
      errors[key] = 'Enter an absolute path without . or .. parts.'
    }
  }
  return Object.keys(errors).length === 0
}

const saveSettings = useTask(async () => {
  if (!validate()) {
    toasts.error('Please correct the highlighted settings.')
    return
  }
  try {
    const res = await api().post<{ settings: AdminSettings; message: string }>('save_settings', { ...form })
    Object.assign(form, res.settings)
    savedSettings.value = JSON.stringify(form)
    toasts.success(res.message)
  } catch (e) {
    Object.assign(errors, toApiError(e).fields)
    throw e
  }
})

function resetSettings(): void {
  if (savedSettings.value) {
    Object.assign(form, JSON.parse(savedSettings.value) as AdminSettings)
  }
  for (const k of Object.keys(errors)) {
    delete errors[k]
  }
}

const policyHelp: Record<string, string> = {
  ssh: 'Users whose account has SSH access (a login shell). Deploy scripts run commands as the user, so this matches what they can already do.',
  all: 'Every DirectAdmin user. Deploy scripts can run any command as the user, even without SSH access.',
  allowlist: 'Only users you allow below.',
}

// ---------------------------------------------------------------- access

const accessUser = ref('')
const accessValue = ref<'allow' | 'deny'>('allow')
const accessError = ref('')
const overrides = ref<AccessOverride[] | null>(null)
const shownOverrides = computed(() => overrides.value ?? d.value?.overrides ?? [])

async function addAccess(): Promise<void> {
  accessError.value = ''
  const user = accessUser.value.trim().toLowerCase()
  if (user === '') {
    accessError.value = 'Enter a username.'
    return
  }
  try {
    const res = await api().post<{ overrides: AccessOverride[]; message: string }>('set_access', { user, value: accessValue.value })
    overrides.value = res.overrides
    accessUser.value = ''
    toasts.success(res.message)
  } catch (e) {
    const err = toApiError(e)
    accessError.value = err.fields.user ?? ''
    throw e
  }
}

async function removeAccess(o: AccessOverride): Promise<void> {
  const ok = await confirm({
    title: `Remove the override for ${o.user}?`,
    message: `${o.user} is then ${form.access_policy === 'allowlist' ? 'not allowed' : 'allowed or not'} according to the policy "${d.value?.policies.find((p) => p.key === form.access_policy)?.label ?? form.access_policy}".`,
    confirmLabel: 'Remove override',
  })
  if (!ok) {
    return
  }
  const res = await api().post<{ overrides: AccessOverride[]; message: string }>('remove_access', { user: o.user })
  overrides.value = res.overrides
  toasts.success(res.message)
}
watch(d, () => {
  overrides.value = null
})

</script>

<template>
  <AppShell>
    <div class="gd-page-head">
      <div>
        <h1 tabindex="-1">Administration <span v-if="d" class="gd-badge gd-badge-muted">v{{ d.version }}</span></h1>
        <div class="gd-page-sub">Every user's sites, server-wide settings and who may use the plugin.</div>
      </div>
      <UpdatedAt :at="r.updatedAt.value" :loading="r.loading.value" />
    </div>

    <Skeleton v-if="!d && !r.error.value" :cards="3" />
    <ErrorState v-else-if="!d && r.error.value" :error="r.error.value" :retry="r.refresh" />

    <template v-else-if="d">
      <Alert v-if="d.permission_fix === 'install'" tone="err">
        <strong>Settings cannot be saved.</strong>
        <template v-if="d.data_owner && d.data_owner !== d.identity">
          The data directory belongs to <code>{{ d.data_owner }}</code>, but DirectAdmin runs admin pages as <code>{{ d.identity }}</code>.
        </template>
        <template v-else>The data directory is not writable by <code>{{ d.identity }}</code>, the account DirectAdmin runs admin pages as.</template>
        Run this as root. It gives the data directory to the main admin account and fixes its permissions:
        <CopyField :text="d.install_command" label="install command" />
      </Alert>
      <Alert v-else-if="d.permission_fix === 'main_admin'" tone="info">
        <strong>This page is read-only for {{ d.identity }}.</strong>
        Laravel Github deploy is managed by the main DirectAdmin admin account, <code>{{ d.data_owner }}</code>.
        Log in as {{ d.data_owner }} to see every user's sites and to change settings and access.
      </Alert>
      <Alert v-if="d.status_readable && !cronHealthy" tone="err">
        <strong>The scheduler is not running.</strong>
        {{ lastRun ? 'The last cron run was' : 'There has been no cron run yet' }}<template v-if="lastRun"> <RelativeTime :ts="lastRun" /></template>,
        so nothing is checked or deployed. Check that <code>/etc/cron.d/laravel_github_deploy</code> exists, or reinstall the plugin.
      </Alert>

      <div class="gd-cols">
        <section class="gd-card">
          <h2>Status</h2>
          <dl class="gd-dl">
            <dt>Last cron run</dt>
            <dd v-if="!d.status_readable">
              <span class="gd-badge gd-badge-muted">Unknown</span>
              <span class="gd-muted gd-small" style="margin-left: 6px">{{ d.identity }} cannot read the status files</span>
            </dd>
            <dd v-else>
              <RelativeTime :ts="lastRun" never="never" />
              <span class="gd-badge" :class="cronHealthy ? 'gd-badge-ok' : 'gd-badge-err'" style="margin-left: 6px">
                {{ cronHealthy ? 'Running' : 'Not running' }}
              </span>
            </dd>
            <dt>Users with sites</dt>
            <dd>{{ d.dispatcher?.eligible_users ?? 0 }}</dd>
            <dt>Sites</dt>
            <dd>{{ allSites.length }}</dd>
            <dt>Needs attention</dt>
            <dd>
              <span v-if="failing.length === 0" class="gd-ok-text">Nothing</span>
              <button v-else type="button" class="gd-link gd-err-text" @click="filter = 'problems'">
                {{ failing.length }} {{ failing.length === 1 ? 'site' : 'sites' }} with problems
              </button>
            </dd>
          </dl>
        </section>
        <section class="gd-card">
          <div class="gd-card-head">
            <h2>Diagnostics</h2>
            <span v-if="diagProblems === 0" class="gd-badge gd-badge-ok"><Icon name="check" />All good</span>
            <span v-else class="gd-badge gd-badge-err">{{ diagProblems }} {{ diagProblems === 1 ? 'problem' : 'problems' }}</span>
          </div>
          <dl class="gd-dl">
            <template v-for="row in d.diagnostics" :key="row.label">
              <dt>{{ row.label }}</dt>
              <dd :class="row.ok ? '' : row.optional ? 'gd-warn-text' : 'gd-err-text'">
                <Icon :name="row.ok ? 'check' : 'alert'" :class="row.ok ? 'gd-ok-text' : ''" /> {{ row.value }}
                <span v-if="row.optional && !row.ok" class="gd-muted gd-small">(optional)</span>
              </dd>
            </template>
          </dl>
        </section>
      </div>

      <section class="gd-card gd-flush">
        <div class="gd-card-head">
          <h2>All sites</h2>
          <div class="gd-btn-row">
            <select v-model="filter" aria-label="Show" style="width: auto">
              <option value="all">All sites</option>
              <option value="problems">Only problems</option>
            </select>
            <input v-model="search" type="text" placeholder="Search user, site or repository" aria-label="Search sites" style="width: 260px" />
          </div>
        </div>
        <div v-for="u in userErrors" :key="u.user" class="gd-pad" style="padding-bottom: 0">
          <Alert tone="err">{{ u.user }}: {{ u.error }}</Alert>
        </div>
        <p v-if="!d.status_readable" class="gd-pad gd-muted">
          Sites cannot be shown: <code>{{ d.identity }}</code> cannot read the status files in the data directory.
        </p>
        <p v-else-if="allSites.length === 0" class="gd-pad gd-muted">No user has configured a site yet.</p>
        <p v-else-if="shownSites.length === 0" class="gd-pad gd-muted">
          No sites match. <button type="button" class="gd-link" @click="(search = ''), (filter = 'all')">Show all</button>
        </p>
        <table v-else class="gd-table">
          <thead>
            <tr>
              <th>User</th>
              <th>Site</th>
              <th class="gd-hide-sm">Live</th>
              <th>Last deployment</th>
              <th class="gd-hide-sm">Reported</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in shownSites" :key="`${s.user}/${s.id}`">
              <td>{{ s.user }}</td>
              <td>
                {{ s.hostname }} <EnvBadge :environment="s.environment" />
                <span v-if="!s.auto_deploy" class="gd-muted gd-small"> (auto off)</span>
                <div class="gd-sub">{{ s.repo }} · {{ s.source }}</div>
              </td>
              <td class="gd-hide-sm gd-nowrap"><code v-if="s.current_label">{{ s.current_label }}</code><span v-else class="gd-muted">—</span></td>
              <td>
                <StatusBadge :status="s.last_status" />
                <span class="gd-muted gd-small"> <RelativeTime :ts="s.last_at" never="" /></span>
                <div v-if="s.last_error && s.last_status !== 'success'" class="gd-sub gd-err-text">{{ trim(s.last_error, 160) }}</div>
                <div v-if="s.poll_error" class="gd-sub gd-err-text">GitHub: {{ trim(s.poll_error, 160) }}</div>
              </td>
              <td class="gd-hide-sm gd-muted"><RelativeTime :ts="s.updated_at" /></td>
            </tr>
          </tbody>
        </table>
      </section>

      <form class="gd-card" novalidate @submit.prevent="saveSettings.run()">
        <h2>Settings</h2>
        <fieldset :disabled="!d.writable" style="border: 0; padding: 0; margin: 0">
          <div class="gd-grid">
            <FormField id="gd-poll" label="Check GitHub every (minutes)" :error="errors.poll_interval" hint="1–60. Checks use conditional requests, so they barely count towards GitHub's rate limit.">
              <input id="gd-poll" v-model.number="form.poll_interval" type="number" min="1" max="60" />
            </FormField>
            <FormField id="gd-timeout" label="Deploy timeout (minutes)" :error="errors.deploy_timeout" hint="1–180. A deploy script running longer is stopped and the release discarded.">
              <input id="gd-timeout" v-model.number="form.deploy_timeout" type="number" min="1" max="180" />
            </FormField>
            <FormField id="gd-keep" label="Default releases to keep" :error="errors.keep_releases" hint="For new sites.">
              <input id="gd-keep" v-model.number="form.keep_releases" type="number" min="1" max="50" />
            </FormField>
            <FormField id="gd-maxkeep" label="Maximum releases a user may keep" :error="errors.max_keep_releases" hint="Each release is a full copy of the application on disk.">
              <input id="gd-maxkeep" v-model.number="form.max_keep_releases" type="number" min="1" max="50" />
            </FormField>
            <FormField id="gd-parallel" label="Users processed in parallel" :error="errors.max_parallel" hint="1–32. Each user's sites run one after another.">
              <input id="gd-parallel" v-model.number="form.max_parallel" type="number" min="1" max="32" />
            </FormField>
            <FormField id="gd-policy" label="Who may use Laravel Github deploy" :error="errors.access_policy" :hint="policyHelp[form.access_policy]">
              <select id="gd-policy" v-model="form.access_policy">
                <option v-for="p in d.policies" :key="p.key" :value="p.key">{{ p.label }}</option>
              </select>
            </FormField>
          </div>
          <h3 style="margin-top: 12px">Node.js for deploy scripts</h3>
          <p class="gd-hint" style="margin-top: 0">
            Leave empty to use what deploy scripts find on <code>PATH</code>. A path set here takes precedence on every user's
            deploy <code>PATH</code>, whatever the file is called (for example <code>node-22</code>); only a script that changes
            <code>PATH</code> itself, such as with nvm, uses another one. It must be Node.js {{ d.node_min_major }} or newer and is checked
            when you save.
          </p>
          <div class="gd-grid">
            <FormField id="gd-node-path" label="Node.js binary" optional :error="errors.node_path" hint="For example /opt/alt/alt-nodejs22/root/usr/bin/node (CloudLinux) or /usr/local/node22/bin/node.">
              <input id="gd-node-path" v-model="form.node_path" type="text" class="gd-code" placeholder="from PATH" autocomplete="off" spellcheck="false" />
            </FormField>
            <FormField id="gd-npm-path" label="npm binary" optional :error="errors.npm_path" hint="Leave empty to use the npm next to the Node.js binary.">
              <input id="gd-npm-path" v-model="form.npm_path" type="text" class="gd-code" :placeholder="form.node_path ? 'next to Node.js' : 'from PATH'" autocomplete="off" spellcheck="false" />
            </FormField>
          </div>
          <label class="gd-check">
            <input v-model="form.reload_fpm" type="checkbox" />
            <span>
              Gracefully reload PHP-FPM after a release is activated
              <span class="gd-hint" style="display: block; margin: 0">Clears PHP's realpath cache so the new release is served immediately instead of after up to 2 minutes.</span>
            </span>
          </label>
          <div class="gd-actions">
            <AsyncButton type="submit" variant="primary" :busy="saveSettings.busy.value" busy-label="Saving…" :disabled="!dirty || !d.writable" :reason="readOnlyReason || 'No changes to save'">Save settings</AsyncButton>
            <button v-if="dirty" type="button" class="gd-btn" @click="resetSettings">Undo changes</button>
            <span v-if="dirty" class="gd-muted gd-small">Unsaved changes</span>
          </div>
        </fieldset>
      </form>

      <section class="gd-card">
        <h2>Per-user access</h2>
        <p class="gd-hint" style="margin-top: 0">Overrides the policy above for individual users. Deploy scripts run any command as the user, comparable to SSH access.</p>
        <table v-if="shownOverrides.length" class="gd-table gd-compact" style="max-width: 520px">
          <tbody>
            <tr v-for="o in shownOverrides" :key="o.user">
              <td>{{ o.user }}</td>
              <td><span class="gd-badge" :class="o.value === 'allow' ? 'gd-badge-ok' : 'gd-badge-err'">{{ o.value === 'allow' ? 'Allowed' : 'Denied' }}</span></td>
              <td class="gd-right">
                <AsyncButton variant="link" busy-label="Removing…" :run="() => removeAccess(o)" :disabled="!d.writable" :reason="readOnlyReason">Remove</AsyncButton>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-else class="gd-muted">No overrides; the policy applies to everyone.</p>
        <form class="gd-row" novalidate @submit.prevent>
          <FormField id="gd-access-user" :error="accessError" style="width: 240px">
            <input id="gd-access-user" v-model="accessUser" type="text" placeholder="username" aria-label="Username" autocomplete="off" spellcheck="false" :disabled="!d.writable" />
          </FormField>
          <select v-model="accessValue" aria-label="Access" style="width: auto" :disabled="!d.writable">
            <option value="allow">Allow</option>
            <option value="deny">Deny</option>
          </select>
          <AsyncButton type="submit" icon="plus" busy-label="Saving…" :run="addAccess" :disabled="!d.writable" :reason="readOnlyReason">Add override</AsyncButton>
        </form>
      </section>
    </template>
  </AppShell>
</template>
