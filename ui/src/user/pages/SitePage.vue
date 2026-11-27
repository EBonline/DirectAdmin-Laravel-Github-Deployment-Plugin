<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import type { DocrootResult, Message, ReleaseOnDisk, SiteDetail } from '../../api/types'
import Alert from '../../components/Alert.vue'
import AsyncButton from '../../components/AsyncButton.vue'
import CopyField from '../../components/CopyField.vue'
import DeployProgress from '../../components/DeployProgress.vue'
import EnvBadge from '../../components/EnvBadge.vue'
import ErrorState from '../../components/ErrorState.vue'
import Icon from '../../components/Icon.vue'
import PageHeader from '../../components/PageHeader.vue'
import RelativeTime from '../../components/RelativeTime.vue'
import Skeleton from '../../components/Skeleton.vue'
import StatusBadge from '../../components/StatusBadge.vue'
import ToggleSwitch from '../../components/ToggleSwitch.vue'
import UpdatedAt from '../../components/UpdatedAt.vue'
import { confirm } from '../../composables/confirm'
import { isBusy, useDeployWatch } from '../../composables/deploys'
import { reportError, usePolling, useResource } from '../../composables/resource'
import { toasts } from '../../composables/toasts'
import { api } from '../../services'
import { capitalize, dateTime, duration, trim } from '../../utils/format'

const route = useRoute()
const id = String(route.query.site ?? '')

const r = useResource(() => api().get<SiteDetail>('site', { site: id }))
const d = computed(() => r.data.value)
const busy = computed(() => (d.value ? isBusy(d.value) : false))
const poll = usePolling(r.refresh, () => (busy.value ? 3000 : 20000))
useDeployWatch(() => (d.value ? [d.value] : null))

const isProd = computed(() => d.value?.site.environment === 'production')
const kind = computed(() => (isProd.value ? 'release' : 'commit'))
const busyReason = computed(() => (d.value?.running ? 'A deployment is running' : d.value?.queued ? 'An action is already queued' : undefined))
const docrootOk = computed(() => d.value?.docroot?.ok === true)
const showAllHistory = ref(false)
const history = computed(() => {
  const h = d.value?.history ?? []
  return showAllHistory.value ? h : h.slice(0, 10)
})

// ------------------------------------------------------------------ actions

async function deployNow(): Promise<void> {
  const res = await api().post<Message>('deploy', { site: id })
  toasts.success(res.message)
  await poll.trigger()
}

async function rollback(rel: ReleaseOnDisk): Promise<void> {
  const host = d.value?.site.hostname ?? ''
  const label = rel.entry ? ` (${rel.entry.label})` : ''
  const ok = await confirm({
    title: `Roll back ${host}?`,
    message: `Release ${rel.id}${label} becomes live again. The current release stays on disk, so you can switch back later. The database is not rolled back.`,
    confirmLabel: 'Roll back',
  })
  if (!ok) {
    return
  }
  const res = await api().post<Message>('rollback', { site: id, release: rel.id })
  toasts.success(res.message)
  await poll.trigger()
}

async function checkDocroot(): Promise<void> {
  const res = await api().post<{ docroot: DocrootResult; message: string }>('check_docroot', { site: id })
  if (res.docroot.ok) {
    toasts.success(`Document root verified: ${res.message}`)
  } else {
    toasts.warn(res.message)
  }
  await r.refresh()
}

const autoOverride = ref<boolean | null>(null)
const autoBusy = ref(false)
const auto = computed(() => autoOverride.value ?? d.value?.site.auto_deploy ?? false)

async function setAuto(value: boolean): Promise<void> {
  autoOverride.value = value
  autoBusy.value = true
  try {
    const res = await api().post<Message>('set_auto', { site: id, enabled: value })
    toasts.success(res.message)
    await r.refresh()
  } catch (e) {
    reportError(e)
  } finally {
    autoOverride.value = null
    autoBusy.value = false
  }
}

function repoUrl(repo: string): string {
  return `https://github.com/${repo}`
}
</script>

<template>
  <Skeleton v-if="!d && !r.error.value" :cards="3" :lines="3" />
  <template v-else-if="!d && r.error.value">
    <PageHeader title="Site" :back="{ to: { name: 'sites' }, label: 'All sites' }" />
    <ErrorState
      :error="r.error.value"
      :title="r.error.value.status === 404 ? 'Site not found' : undefined"
      :retry="r.error.value.status === 404 ? undefined : r.refresh"
    >
      <p v-if="r.error.value.status === 404"><RouterLink :to="{ name: 'sites' }">Back to your sites</RouterLink></p>
    </ErrorState>
  </template>

  <template v-else-if="d">
    <PageHeader :title="d.site.hostname" :back="{ to: { name: 'sites' }, label: 'All sites' }">
      <template #badges><EnvBadge :environment="d.site.environment" /></template>
      <template #sub>
        <a :href="repoUrl(d.site.repo)" target="_blank" rel="noopener">{{ d.site.repo }} <Icon name="external" /></a>
        · {{ d.site.source_label }}
      </template>
      <template #actions>
        <UpdatedAt :at="r.updatedAt.value" :loading="r.loading.value" />
        <RouterLink class="gd-btn" :to="{ name: 'env', query: { site: id } }"><Icon name="file" />Edit .env</RouterLink>
        <RouterLink class="gd-btn" :to="{ name: 'edit', query: { site: id } }"><Icon name="settings" />Site settings</RouterLink>
      </template>
    </PageHeader>

    <Alert v-if="!d.has_token" tone="warn">
      There is no GitHub token for this site, so it cannot check GitHub or deploy.
      <RouterLink :to="{ name: 'settings' }">Add a token in Settings</RouterLink>.
    </Alert>
    <Alert v-else-if="d.poll_error" tone="err">
      <strong>The last GitHub check failed:</strong> {{ d.poll_error }}
      <div class="gd-small">Checked <RelativeTime :ts="d.last_poll" />. If the token expired, replace it in <RouterLink :to="{ name: 'settings' }">Settings</RouterLink>.</div>
    </Alert>
    <Alert v-if="d.update_available && !auto && !busy && d.remote" tone="warn">
      New {{ kind }} available: <code>{{ d.remote.label }}</code> {{ trim(d.remote.message, 80) }}.
      Automatic deployments are paused for this site.
      <div style="margin-top: 6px">
        <AsyncButton size="sm" icon="play" busy-label="Queueing…" :run="deployNow">Deploy it now</AsyncButton>
      </div>
    </Alert>

    <DeployProgress :status="d" :deploy-timeout="d.deploy_timeout" @changed="poll.trigger()" />

    <!-- Getting started: until the domain is confirmed to serve the live release. -->
    <section v-if="!docrootOk" class="gd-card gd-step">
      <h2>Finish setting up {{ d.site.hostname }}</h2>
      <ol class="gd-steps">
        <li :class="d.current_id ? 'is-done' : 'is-current'">
          <span class="gd-step-mark"><Icon v-if="d.current_id" name="check" /><template v-else>1</template></span>
          <div class="gd-step-body">
            <div class="gd-step-title">First deployment</div>
            <p v-if="d.current_id" class="gd-muted">Release {{ d.current_id }} is live.</p>
            <p v-else-if="busy">In progress; see above.</p>
            <p v-else-if="isProd && !d.remote">
              This is a production site, so it deploys GitHub releases. Publish a release on GitHub, or
              <RouterLink :to="{ name: 'ref', query: { site: id } }">deploy a specific tag</RouterLink>.
            </p>
            <p v-else-if="d.last?.status === 'failed'" class="gd-err-text">
              The last attempt failed. <RouterLink :to="{ name: 'log', query: { site: id, id: d.last.id } }">Read the log</RouterLink>,
              fix the problem and deploy again.
            </p>
            <p v-else-if="auto">It starts automatically within a minute, or deploy now with the button below.</p>
            <p v-else>Automatic deployments are off. Press <strong>Deploy</strong> below when you are ready.</p>
          </div>
        </li>
        <li>
          <span class="gd-step-mark">2</span>
          <div class="gd-step-body">
            <div class="gd-step-title">Fill in the environment file</div>
            <p>
              Set <code>APP_KEY</code>, the database and other settings in <code>.env</code>
              <template v-if="!d.env_exists">(it is created from <code>.env.example</code> on the first deployment)</template>,
              then deploy again so Laravel picks them up.
              <RouterLink :to="{ name: 'env', query: { site: id } }">Edit .env →</RouterLink>
            </p>
          </div>
        </li>
        <li :class="{ 'is-current': !!d.current_id }">
          <span class="gd-step-mark">3</span>
          <div class="gd-step-body">
            <div class="gd-step-title">Point the document root to the live release</div>
            <p>
              Laravel Github deploy never changes <code>public_html</code>. In DirectAdmin, change the document root of
              <strong>{{ d.site.hostname }}</strong> to:
            </p>
            <CopyField :text="d.site.docroot_path" label="document root path" />
            <template v-if="d.docroot_rule">
              <p>
                A DirectAdmin administrator does this in <strong>Admin Level → Custom HTTPD Configurations →
                {{ d.docroot_rule.domain }} → httpd.conf</strong>, by pasting these lines into the top text box and saving.
                They set the document root for HTTP and HTTPS.
              </p>
              <CopyField :text="d.docroot_rule.snippet" label="Custom HTTPD Configurations lines" multiline />
              <p class="gd-hint">
                <template v-if="d.docroot_rule.sub">
                  The subdomain <strong>{{ d.docroot_rule.sub }}</strong> must exist in DirectAdmin. The first line limits
                  the change to {{ d.site.hostname }}, so {{ d.docroot_rule.domain }} and its other subdomains keep their
                  document root. On DirectAdmin 1.61 or newer you can also set it yourself, without an administrator:
                  <strong>Sub-Domains Setup</strong>, then the pencil icon next to {{ d.docroot_rule.sub }}.
                </template>
                <template v-else>
                  The first line keeps the subdomains of {{ d.docroot_rule.domain }} on their own document root.
                </template>
                If the server runs nginx, paste the same lines into <strong>nginx.conf</strong> on that page as well.
                If you are not an administrator, send these lines to your hosting administrator.
                <template v-if="!d.current_id">The path exists after the first successful deployment.</template>
              </p>
            </template>
            <p v-else class="gd-hint">
              If the domain has a separate SSL document root (<code>private_html</code>), change that one to the same path.
              If you cannot change the document root yourself, ask your hosting administrator.
              <template v-if="!d.current_id">The path exists after the first successful deployment.</template>
            </p>
          </div>
        </li>
        <li>
          <span class="gd-step-mark">4</span>
          <div class="gd-step-body">
            <div class="gd-step-title">Check that the domain serves the live release</div>
            <p class="gd-muted">This places a temporary file in the live release and requests it from {{ d.site.hostname }}.</p>
            <Alert v-if="d.docroot && !d.docroot.ok" tone="err">
              {{ d.docroot.message }} <span class="gd-small">(<RelativeTime :ts="d.docroot.checked_at" />)</span>
            </Alert>
            <AsyncButton
              variant="primary"
              icon="globe"
              busy-label="Requesting the site…"
              :run="checkDocroot"
              :disabled="!d.current_id"
              reason="Deploy the site first"
            >
              Check document root
            </AsyncButton>
          </div>
        </li>
      </ol>
    </section>

    <section v-if="!busy" class="gd-card">
      <h2>Deploy</h2>
      <div class="gd-btn-row">
        <AsyncButton variant="primary" icon="play" busy-label="Queueing…" :run="deployNow" :disabled="!d.has_token" reason="Add a GitHub token first">
          Deploy {{ isProd ? 'latest release' : `latest commit on ${d.site.branch}` }}
        </AsyncButton>
        <RouterLink class="gd-btn" :to="{ name: 'ref', query: { site: id } }"><Icon :name="isProd ? 'tag' : 'branch'" />Choose a {{ kind }}…</RouterLink>
      </div>
      <p class="gd-hint" style="margin-top: 10px">
        A deployment is queued, starts within a minute, and is built next to the live release. Visitors switch to it only when
        every step has succeeded.
      </p>
    </section>

    <div class="gd-cols">
      <section class="gd-card">
        <h2>Live</h2>
        <template v-if="d.current_id">
          <dl class="gd-dl">
            <dt>Release</dt>
            <dd><code>{{ d.current_id }}</code></dd>
            <template v-if="d.current">
              <dt>{{ isProd ? 'Tag' : 'Commit' }}</dt>
              <dd>
                <a v-if="d.current.url" :href="d.current.url" target="_blank" rel="noopener"><code>{{ d.current.label }}</code></a>
                <code v-else>{{ d.current.label }}</code>
              </dd>
              <dt>Message</dt>
              <dd>{{ d.current.message || '—' }}</dd>
              <dt>Deployed</dt>
              <dd><RelativeTime :ts="d.current.finished_at" /> <span class="gd-muted">({{ dateTime(d.current.finished_at) }})</span></dd>
            </template>
            <dt>Path</dt>
            <dd><code>{{ d.site.current_path }}</code></dd>
          </dl>
          <div v-if="docrootOk" class="gd-btn-row">
            <span class="gd-ok-text gd-inline-flex"><Icon name="circleCheck" />Document root verified <RelativeTime :ts="d.docroot?.checked_at" /></span>
            <AsyncButton variant="link" busy-label="Checking…" :run="checkDocroot">Check again</AsyncButton>
          </div>
        </template>
        <p v-else class="gd-muted">Nothing is deployed yet.</p>
      </section>

      <section class="gd-card">
        <h2>GitHub</h2>
        <dl class="gd-dl">
          <dt>{{ isProd ? 'Latest release' : 'Branch head' }}</dt>
          <dd>
            <template v-if="d.remote">
              <a v-if="d.remote.url" :href="d.remote.url" target="_blank" rel="noopener"><code>{{ d.remote.label }}</code></a>
              <code v-else>{{ d.remote.label }}</code>
              <span class="gd-muted"> {{ trim(d.remote.message, 80) }}</span>
            </template>
            <span v-else-if="d.last_poll" class="gd-muted">{{ isProd ? 'No published release yet' : 'Unknown' }}</span>
            <span v-else class="gd-muted">Not checked yet</span>
          </dd>
          <dt>Checked</dt>
          <dd><RelativeTime :ts="d.last_poll" never="not yet" /> <span class="gd-muted">(every {{ d.poll_interval }} min)</span></dd>
          <dt>Auto-deploy</dt>
          <dd>
            <ToggleSwitch
              :model-value="auto"
              :busy="autoBusy"
              :label="`Automatic deployments for ${d.site.hostname}`"
              on-text="On: new versions deploy automatically"
              off-text="Off: deploy by hand"
              @update:model-value="setAuto"
            />
          </dd>
        </dl>
      </section>
    </div>

    <section class="gd-card gd-flush">
      <div class="gd-card-head"><h2>Deployments</h2></div>
      <p v-if="d.history.length === 0" class="gd-pad gd-muted">No deployments yet.</p>
      <table v-else class="gd-table">
        <thead>
          <tr>
            <th>Started</th>
            <th>What</th>
            <th>Status</th>
            <th class="gd-hide-sm">Duration</th>
            <th class="gd-hide-sm">Trigger</th>
            <th><span class="gd-sr">Log</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="h in history" :key="h.id">
            <td class="gd-nowrap"><RelativeTime :ts="h.started_at" /></td>
            <td>
              <template v-if="h.type === 'rollback'">Rollback to <code>{{ h.release }}</code> <span class="gd-muted">{{ h.label }}</span></template>
              <template v-else>
                <code>{{ h.label }}</code> <span class="gd-muted">{{ trim(h.message, 80) }}</span>
              </template>
              <div v-if="h.error" class="gd-error"><Icon name="alert" /><span>{{ h.error }}</span></div>
            </td>
            <td><StatusBadge :status="h.status" :running="d.running?.id === h.id" /></td>
            <td class="gd-hide-sm">{{ h.finished_at ? duration(h.finished_at - h.started_at) : '—' }}</td>
            <td class="gd-hide-sm">{{ capitalize(h.trigger) }}</td>
            <td class="gd-right"><RouterLink :to="{ name: 'log', query: { site: id, id: h.id } }">Log</RouterLink></td>
          </tr>
        </tbody>
      </table>
      <p v-if="d.history.length > 10" class="gd-pad">
        <button type="button" class="gd-link" @click="showAllHistory = !showAllHistory">
          {{ showAllHistory ? 'Show fewer' : `Show all ${d.history.length} deployments` }}
        </button>
      </p>
    </section>

    <section class="gd-card gd-flush">
      <div class="gd-card-head">
        <h2>Releases on disk</h2>
        <span class="gd-muted gd-small">Keeps {{ d.site.keep_releases }}; older ones are removed after each deployment.</span>
      </div>
      <p v-if="d.releases.length === 0" class="gd-pad gd-muted">No releases yet.</p>
      <table v-else class="gd-table">
        <thead>
          <tr>
            <th>Release</th>
            <th>{{ isProd ? 'Tag' : 'Commit' }}</th>
            <th class="gd-hide-sm">Deployed</th>
            <th><span class="gd-sr">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="rel in d.releases" :key="rel.id" :class="{ 'is-current': rel.current }">
            <td>
              <code>{{ rel.id }}</code>
              <span v-if="rel.current" class="gd-badge gd-badge-ok" style="margin-left: 6px">Live</span>
            </td>
            <td>
              <template v-if="rel.entry"><code>{{ rel.entry.label }}</code> <span class="gd-muted">{{ trim(rel.entry.message, 60) }}</span></template>
              <span v-else class="gd-muted">—</span>
            </td>
            <td class="gd-hide-sm"><RelativeTime v-if="rel.entry?.finished_at" :ts="rel.entry.finished_at" /><span v-else class="gd-muted">—</span></td>
            <td class="gd-right">
              <AsyncButton
                v-if="!rel.current && rel.can_activate"
                size="sm"
                icon="rollback"
                busy-label="Queueing…"
                :run="() => rollback(rel)"
                :disabled="busy"
                :reason="busyReason"
              >
                Roll back
              </AsyncButton>
              <span v-else-if="!rel.can_activate" class="gd-muted gd-small" title="This release never finished deploying">Incomplete</span>
            </td>
          </tr>
        </tbody>
      </table>
    </section>

    <p v-if="!d.env_shared" class="gd-hint">
      Tip: add <code>.env</code> to the shared paths in <RouterLink :to="{ name: 'edit', query: { site: id } }">site settings</RouterLink>
      so it survives deployments.
    </p>
  </template>
</template>
