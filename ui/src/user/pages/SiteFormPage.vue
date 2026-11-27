<script setup lang="ts">
import { computed, nextTick, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { ApiError, toApiError } from '../../api/client'
import type { Environment, Message, RepoCheck, SiteFormData } from '../../api/types'
import Alert from '../../components/Alert.vue'
import AsyncButton from '../../components/AsyncButton.vue'
import CodeEditor from '../../components/CodeEditor.vue'
import ErrorState from '../../components/ErrorState.vue'
import FormField from '../../components/FormField.vue'
import Icon from '../../components/Icon.vue'
import PageHeader from '../../components/PageHeader.vue'
import Skeleton from '../../components/Skeleton.vue'
import { confirm } from '../../composables/confirm'
import { useResource, useTask } from '../../composables/resource'
import { toasts } from '../../composables/toasts'
import { useSaveShortcut, useUnsavedGuard } from '../../composables/unsaved'
import { api } from '../../services'
import { defaultBase, domainOwner, isBranch, isHostname, isToken, normalizeRepo } from '../../utils/validate'

const route = useRoute()
const router = useRouter()
const siteId = String(route.query.site ?? '')

const r = useResource(() =>
  api().get<SiteFormData>('site_form', { site: siteId || undefined, hostname: (route.query.hostname as string) || undefined }),
)
void r.refresh()

const meta = computed(() => r.data.value)
const isNew = computed(() => meta.value?.is_new ?? !siteId)

const form = reactive({
  hostname: '',
  environment: 'testing' as Environment,
  repo: '',
  branch: 'main',
  auto_deploy: true,
  php: 'default',
  web_dir: 'public',
  base_path: '',
  shared_paths: '',
  keep_releases: 5,
  script: '',
  site_token: '',
  clear_site_token: false,
})
const errors = reactive<Record<string, string>>({})
const snapshot = ref<string | null>(null)
const dirty = computed(() => snapshot.value !== null && JSON.stringify(form) !== snapshot.value)

watch(meta, (m) => {
  if (!m) {
    return
  }
  const s = m.site
  Object.assign(form, {
    hostname: s.hostname,
    environment: s.environment,
    repo: s.repo,
    branch: s.branch,
    auto_deploy: s.auto_deploy,
    php: s.php,
    web_dir: s.web_dir,
    base_path: m.is_new ? '' : s.base_path,
    shared_paths: s.shared_paths.join('\n'),
    keep_releases: s.keep_releases,
    script: s.script ?? m.default_script,
    site_token: '',
    clear_site_token: false,
  })
  snapshot.value = JSON.stringify(form)
})

useUnsavedGuard(() => dirty.value)

// ------------------------------------------------------------- validation

const host = computed(() => form.hostname.trim().toLowerCase())
const basePreview = computed(() => {
  if (form.base_path.trim() !== '') {
    return form.base_path.trim()
  }
  return meta.value ? defaultBase(meta.value.home, host.value, meta.value.domains) : null
})
const docrootPreview = computed(() => {
  const base = basePreview.value
  if (!base) {
    return null
  }
  const web = form.web_dir.trim().replace(/^\/+|\/+$/g, '')
  return web === '' || web === '.' ? `${base}/current` : `${base}/current/${web}`
})
const normalizedRepo = computed(() => normalizeRepo(form.repo))

// ------------------------------------- "what happens when you create the site"

/** Values shown in the explanation; null while the field is empty or invalid, so generic wording is shown instead. */
const createHost = computed(() => (isHostname(host.value) ? host.value : null))
const createBranch = computed(() => {
  const b = form.branch.trim() || 'main'
  return isBranch(b) ? b : null
})

// ---------------------------------------------------------------- Node.js

/** The script runs npm/node or the Node.js check. */
const usesNode = computed(() => /\$REQUIRE_NODE\(\)|\bnpm\b|\bnode\b/.test(form.script))
/** The script puts Node.js on PATH itself (nvm, Volta, fnm, PATH=…), which the server cannot see in advance. */
const scriptSetsPath = computed(() => /nvm\.sh|\bnvm use\b|volta|\bfnm\b|\bPATH=/.test(form.script))
/** Why $REQUIRE_NODE() would stop with the PATH deploy scripts start with; `code` is shown as code after `text`. */
const nodeProblem = computed((): { title: string; text: string; code: string } | null => {
  const n = meta.value?.node
  if (!n) {
    return null
  }
  if (n.admin.node && !n.admin.usable) {
    return {
      title: 'The Node.js your server administrator set for deploy scripts cannot be run.',
      text: 'Ask them to check the path:',
      code: n.admin.node,
    }
  }
  const missing = [n.node ? null : 'Node.js', n.npm ? null : 'npm'].filter((x): x is string => x !== null)
  if (missing.length > 0) {
    return {
      title: `${missing.join(' and ')} ${missing.length === 1 ? 'is' : 'are'} not available to deploy scripts.`,
      text: 'Not found on the PATH deploy scripts start with:',
      code: n.path,
    }
  }
  if (n.node && !n.node.supported) {
    return n.node.version === null
      ? { title: 'Node.js does not work for deploy scripts.', text: 'This command failed:', code: `${n.node.path} --version` }
      : {
          title: `Node.js ${n.node.version} is too old for deploy scripts.`,
          text: `They need Node.js ${n.min_major} or newer, and the first one on their PATH is`,
          code: n.node.path,
        }
  }
  return null
})

function scriptError(script: string): string | null {
  const count = (needle: string): number => script.split(needle).length - 1
  const c = count('$CREATE_RELEASE()')
  const a = count('$ACTIVATE_RELEASE()')
  if (c !== 1) {
    return 'The deploy script must contain $CREATE_RELEASE() exactly once.'
  }
  if (a !== 1) {
    return 'The deploy script must contain $ACTIVATE_RELEASE() exactly once.'
  }
  if (script.indexOf('$CREATE_RELEASE()') > script.indexOf('$ACTIVATE_RELEASE()')) {
    return '$CREATE_RELEASE() must come before $ACTIVATE_RELEASE().'
  }
  return null
}

function check(field: string): void {
  const m = meta.value
  let msg: string | null = null
  switch (field) {
    case 'hostname':
      if (host.value === '') {
        msg = 'Enter the domain or subdomain to deploy to.'
      } else if (!isHostname(host.value)) {
        msg = 'Enter a valid domain name, for example test.example.com.'
      } else if (m && domainOwner(host.value, m.domains) === null) {
        msg = m.domains.length
          ? `This is not one of your domains or a subdomain of one (${m.domains.join(', ')}).`
          : 'This is not one of your domains. Add the domain in DirectAdmin first.'
      }
      break
    case 'repo':
      msg = form.repo.trim() === '' ? 'Enter the GitHub repository.' : normalizedRepo.value ? null : 'Use the form owner/repository (or paste the GitHub URL).'
      break
    case 'branch':
      msg = form.environment === 'testing' && !isBranch(form.branch.trim() || 'main') ? 'Enter a valid branch name.' : null
      break
    case 'keep_releases': {
      const max = m?.max_keep ?? 20
      const n = Number(form.keep_releases)
      msg = !Number.isInteger(n) || n < 1 || n > max ? `Choose a number between 1 and ${max}.` : null
      break
    }
    case 'site_token':
      msg = form.site_token.trim() !== '' && !isToken(form.site_token.trim()) ? 'This does not look like a GitHub token.' : null
      break
    case 'script':
      msg = scriptError(form.script)
      break
  }
  if (msg) {
    errors[field] = msg
  } else {
    delete errors[field]
  }
}

const CHECKED = ['hostname', 'repo', 'branch', 'keep_releases', 'site_token', 'script']

function validateAll(): boolean {
  CHECKED.forEach(check)
  return Object.keys(errors).length === 0
}

async function focusFirstError(): Promise<void> {
  await nextTick()
  const el = document.querySelector<HTMLElement>('.gd-field.has-error input, .gd-field.has-error textarea, .gd-field.has-error select')
  el?.scrollIntoView({ block: 'center', behavior: 'smooth' })
  el?.focus({ preventScroll: true })
}

// Clear a field's error as soon as the user changes it.
for (const field of CHECKED) {
  watch(
    () => form[field as keyof typeof form],
    () => {
      if (errors[field]) {
        check(field)
      }
    },
  )
}

// ------------------------------------------------- live repository check

const repoCheck = reactive({ state: 'idle' as 'idle' | 'busy' | 'ok' | 'err', message: '', key: '' })

async function checkRepo(force = false): Promise<void> {
  const repo = normalizedRepo.value
  const branch = form.branch.trim() || 'main'
  if (!repo || (form.environment === 'testing' && !isBranch(branch)) || (form.site_token.trim() !== '' && !isToken(form.site_token.trim()))) {
    repoCheck.state = 'idle'
    return
  }
  const key = JSON.stringify([repo, form.environment, branch, form.site_token.trim(), form.clear_site_token])
  if (!force && key === repoCheck.key && repoCheck.state !== 'err') {
    return
  }
  repoCheck.key = key
  repoCheck.state = 'busy'
  repoCheck.message = ''
  try {
    const res = await api().post<RepoCheck>('check_repo', {
      site: siteId,
      repo,
      environment: form.environment,
      branch,
      site_token: form.site_token.trim(),
      clear_site_token: form.clear_site_token,
    })
    if (repoCheck.key !== key) {
      return
    }
    repoCheck.state = 'ok'
    repoCheck.message = res.message
    for (const f of ['repo', 'branch', 'site_token']) {
      delete errors[f]
    }
  } catch (e) {
    if (repoCheck.key !== key) {
      return
    }
    const err = toApiError(e)
    repoCheck.state = 'err'
    repoCheck.message = Object.values(err.fields)[0] ?? err.message
  }
}

watch(
  () => form.environment,
  () => {
    if (normalizedRepo.value) {
      void checkRepo()
    }
  },
)

// -------------------------------------------------------------- actions

const save = useTask(async () => {
  if (!validateAll()) {
    await focusFirstError()
    throw new ApiError('validation', 'Please correct the highlighted fields.')
  }
  try {
    const res = await api().post<{ site: { id: string }; created: boolean; message: string }>('save_site', {
      site: siteId,
      ...form,
      hostname: host.value,
      repo: normalizedRepo.value ?? form.repo,
      site_token: form.site_token.trim(),
    })
    snapshot.value = JSON.stringify(form)
    toasts.success(res.message)
    await router.push({ name: 'site', query: { site: res.site.id } })
  } catch (e) {
    const err = toApiError(e)
    if (err.code === 'validation') {
      Object.assign(errors, err.fields)
      if (err.fields.repo || err.fields.branch || err.fields.site_token) {
        repoCheck.state = 'err'
        repoCheck.message = err.fields.repo ?? err.fields.branch ?? err.fields.site_token ?? ''
      }
      await focusFirstError()
    }
    throw err
  }
})
useSaveShortcut(() => void save.run())

async function resetScript(): Promise<void> {
  const m = meta.value
  if (!m || form.script === m.default_script) {
    return
  }
  const ok = await confirm({
    title: 'Use the default deploy script?',
    message: 'This replaces the script below with the default Laravel deploy script. Nothing is saved until you press Save.',
    confirmLabel: 'Replace script',
  })
  if (ok) {
    form.script = m.default_script
    toasts.info('Default script restored. Press Save to keep it.')
  }
}

async function removeSite(): Promise<void> {
  const m = meta.value
  if (!m) {
    return
  }
  const ok = await confirm({
    title: `Remove ${m.site.hostname}?`,
    message: `Deployments stop, and the site's configuration, history and logs are deleted. The deployed files in ${m.site.base_path} stay where they are, so the domain keeps working.`,
    confirmLabel: 'Remove site',
    tone: 'danger',
    requireText: m.site.hostname,
  })
  if (!ok) {
    return
  }
  const res = await api().post<Message>('delete_site', { site: siteId, confirm: m.site.hostname })
  snapshot.value = JSON.stringify(form)
  toasts.success(res.message)
  await router.push({ name: 'sites' })
}

const back = computed(() => (siteId ? { to: { name: 'site', query: { site: siteId } }, label: meta.value?.site.hostname ?? 'Site' } : { to: { name: 'sites' }, label: 'All sites' }))
</script>

<template>
  <PageHeader :title="isNew ? 'Add a site' : `Settings of ${meta?.site.hostname ?? ''}`" :back="back" />

  <Skeleton v-if="!meta && !r.error.value" :cards="3" />
  <ErrorState v-else-if="!meta && r.error.value" :error="r.error.value" :retry="r.error.value.status === 404 ? undefined : r.refresh" />

  <form v-else-if="meta" novalidate @submit.prevent="save.run()">
    <Alert v-if="!meta.has_default_token && isNew" tone="warn">
      You have no GitHub token yet. Add one in <RouterLink :to="{ name: 'settings' }">Settings</RouterLink>, or enter a token for this
      site below.
    </Alert>
    <Alert v-if="Object.keys(errors).length > 0" tone="err">Please correct the highlighted fields.</Alert>

    <section class="gd-card">
      <h2>Site</h2>
      <FormField id="gd-hostname" label="Domain or subdomain" :error="errors.hostname">
        <input
          id="gd-hostname"
          v-model="form.hostname"
          type="text"
          list="gd-domains"
          placeholder="test.example.com"
          autocomplete="off"
          autocapitalize="off"
          spellcheck="false"
          :aria-invalid="!!errors.hostname"
          aria-describedby="gd-hostname-hint"
          @blur="check('hostname')"
        />
        <datalist id="gd-domains">
          <option v-for="dom in meta.domains" :key="dom" :value="dom"></option>
        </datalist>
        <template v-if="!errors.hostname" #hint>
          <template v-if="basePreview">Deployed to <code>{{ basePreview }}</code>.</template>
          <template v-else>One of your domains ({{ meta.domains.join(', ') || 'none found' }}) or a subdomain of one.</template>
        </template>
      </FormField>

      <fieldset class="gd-field">
        <legend>What should be deployed?</legend>
        <div class="gd-choices">
          <label class="gd-choice" :class="{ 'is-selected': form.environment === 'testing' }">
            <input v-model="form.environment" type="radio" name="environment" value="testing" />
            <span><strong>Testing</strong><span class="gd-muted">Every new commit on a branch.</span></span>
          </label>
          <label class="gd-choice" :class="{ 'is-selected': form.environment === 'production' }">
            <input v-model="form.environment" type="radio" name="environment" value="production" />
            <span><strong>Production</strong><span class="gd-muted">Every new published GitHub release (drafts and pre-releases are skipped).</span></span>
          </label>
        </div>
      </fieldset>

      <div class="gd-grid">
        <FormField id="gd-repo" label="GitHub repository" :error="errors.repo">
          <input
            id="gd-repo"
            v-model="form.repo"
            type="text"
            placeholder="owner/repository"
            autocomplete="off"
            autocapitalize="off"
            spellcheck="false"
            :aria-invalid="!!errors.repo"
            @blur="check('repo'), checkRepo()"
          />
          <template v-if="normalizedRepo && normalizedRepo !== form.repo.trim()" #hint>Saved as <code>{{ normalizedRepo }}</code>.</template>
        </FormField>
        <FormField v-if="form.environment === 'testing'" id="gd-branch" label="Branch" :error="errors.branch">
          <input
            id="gd-branch"
            v-model="form.branch"
            type="text"
            placeholder="main"
            autocomplete="off"
            autocapitalize="off"
            spellcheck="false"
            :aria-invalid="!!errors.branch"
            @blur="check('branch'), checkRepo()"
          />
        </FormField>
      </div>
      <div v-if="repoCheck.state !== 'idle'" class="gd-check-status" :class="`is-${repoCheck.state}`" role="status" style="margin: -6px 0 14px">
        <template v-if="repoCheck.state === 'busy'"><span class="gd-spinner" aria-hidden="true"></span>Checking access on GitHub…</template>
        <template v-else-if="repoCheck.state === 'ok'"><Icon name="circleCheck" /><span>{{ repoCheck.message }}</span></template>
        <template v-else>
          <Icon name="alert" />
          <span>{{ repoCheck.message }} <button type="button" class="gd-link" @click="checkRepo(true)">Check again</button></span>
        </template>
      </div>

      <label class="gd-check">
        <input v-model="form.auto_deploy" type="checkbox" />
        <span>
          Deploy automatically
          <span class="gd-hint" style="display: block; margin: 0">
            {{
              form.environment === 'testing'
                ? `New commits on ${form.branch || 'the branch'} are deployed within a few minutes.`
                : 'New releases are deployed within a few minutes of being published.'
            }}
            When off, you deploy by hand and the site page tells you when something new is available.
          </span>
        </span>
      </label>
    </section>

    <section class="gd-card">
      <h2>Server</h2>
      <div class="gd-grid">
        <FormField id="gd-php" label="PHP version" :error="errors.php" hint="Used by the deploy script. Choose the version your domain runs.">
          <select id="gd-php" v-model="form.php">
            <option v-for="v in meta.php_versions" :key="v.key" :value="v.key">{{ v.label }}</option>
          </select>
        </FormField>
        <FormField id="gd-webdir" label="Web directory" :error="errors.web_dir">
          <input id="gd-webdir" v-model="form.web_dir" type="text" placeholder="public" autocomplete="off" spellcheck="false" />
          <template #hint>The directory inside the repository that is served, e.g. <code>public</code>. Use <code>.</code> for the repository root.</template>
        </FormField>
      </div>
      <FormField id="gd-base" label="Base path" optional :error="errors.base_path">
        <input
          id="gd-base"
          v-model="form.base_path"
          type="text"
          :placeholder="(meta && host && defaultBase(meta.home, host, meta.domains)) || `${meta.home}/domains/<domain>`"
          autocomplete="off"
          spellcheck="false"
        />
        <template #hint>
          Holds <code>releases/</code>, <code>shared/</code> and the <code>current</code> symlink. Leave empty for the default.
          <template v-if="docrootPreview"><br />The document root will be <code>{{ docrootPreview }}</code>.</template>
        </template>
      </FormField>
      <div class="gd-grid">
        <FormField id="gd-shared" label="Shared paths" :error="errors.shared_paths">
          <textarea id="gd-shared" v-model="form.shared_paths" rows="3" class="gd-code" spellcheck="false"></textarea>
          <template #hint>One per line. Kept in <code>shared/</code> and linked into every release, so they survive deployments.</template>
        </FormField>
        <FormField
          id="gd-keep"
          label="Releases to keep"
          :error="errors.keep_releases"
          :hint="`1–${meta.max_keep}. Older releases are removed after each deployment; the ones kept allow an instant rollback.`"
        >
          <input id="gd-keep" v-model.number="form.keep_releases" type="number" min="1" :max="meta.max_keep" @blur="check('keep_releases')" />
        </FormField>
      </div>
    </section>

    <section class="gd-card">
      <div class="gd-card-head">
        <h2>Deploy script</h2>
        <button type="button" class="gd-link" :disabled="form.script === meta.default_script" @click="resetScript">
          <Icon name="refresh" />Use the default script
        </button>
      </div>
      <p class="gd-hint" style="margin-top: 0">
        Runs with bash as your user. <code>$CREATE_RELEASE()</code> checks out the commit into a new release directory and links the
        shared paths; <code>$ACTIVATE_RELEASE()</code> makes it live. If a command fails before activation, the release is thrown
        away and the site keeps running the previous one.
      </p>
      <Alert v-if="meta.outdated_script && form.script !== meta.default_script" tone="info">
        This is the previous default script. When npm is missing it skips the frontend build without a warning, so a release can
        go live without its assets. The current default stops the deployment instead and says why.
        <button type="button" class="gd-link" @click="resetScript">Use the current default script</button>
      </Alert>
      <template v-if="usesNode">
        <Alert v-if="nodeProblem && !scriptSetsPath" tone="warn">
          <strong>{{ nodeProblem.title }}</strong> {{ nodeProblem.text }} <code>{{ nodeProblem.code }}</code>. Deployments that need a
          frontend build stop at <code>$REQUIRE_NODE()</code>, and the live release keeps running.
          <template v-if="meta.node.hint?.ssh">{{ meta.node.hint.text }}: run <code>{{ meta.node.hint.line }}</code> once over SSH.</template>
          <template v-else-if="meta.node.hint">
            {{ meta.node.hint.text }}: add <code>{{ meta.node.hint.line }}</code> above <code>$REQUIRE_NODE()</code>.
          </template>
          <template v-else>
            Install Node.js {{ meta.node.min_major }} or newer for your account (for example with nvm), or ask your hosting provider.
          </template>
        </Alert>
        <Alert v-else-if="nodeProblem" tone="info">
          <strong>Before your script changes PATH:</strong> {{ nodeProblem.title }} The log of the next deployment shows what your
          script finds; <code>$REQUIRE_NODE()</code> needs Node.js {{ meta.node.min_major }} or newer and npm.
        </Alert>
        <p v-else class="gd-hint">
          <Icon name="check" class="gd-ok-text" /> Node.js {{ meta.node.node?.version ?? '(version unknown)' }} and npm are available to
          deploy scripts<template v-if="meta.node.node?.path.includes('/versions/node/')">, from nvm</template>.
          <template v-if="meta.node.admin.node">
            Set by your server administrator: <code>{{ meta.node.admin.node }}</code>
            <template v-if="meta.node.admin.npm"> and <code>{{ meta.node.admin.npm }}</code></template>.
          </template>
        </p>
      </template>
      <FormField id="gd-script" :error="errors.script">
        <CodeEditor id="gd-script" v-model="form.script" :rows="16" label="Deploy script" :max-bytes="65536" @blur="check('script')" />
      </FormField>
      <details class="gd-details">
        <summary>Available variables and macros</summary>
        <table class="gd-table gd-compact">
          <tbody>
            <tr><td><code>$CREATE_RELEASE()</code></td><td>Create the release directory (required)</td></tr>
            <tr><td><code>$ACTIVATE_RELEASE()</code></td><td>Make the release live (required)</td></tr>
            <tr><td><code>$RESTART_QUEUES()</code></td><td><code>php artisan queue:restart</code> in the live release</td></tr>
            <tr>
              <td><code>$REQUIRE_NODE()</code></td>
              <td>
                Stop the deployment unless Node.js {{ meta.node.min_major }} or newer and <code>npm</code> are on <code>PATH</code>; logs
                their versions
              </td>
            </tr>
            <tr><td><code>$DEPLOY_RELEASE_DIRECTORY</code></td><td>The new release</td></tr>
            <tr><td><code>$DEPLOY_SITE_PATH</code></td><td>The <code>current</code> symlink</td></tr>
            <tr><td><code>$DEPLOY_SITE_ROOT</code>, <code>$DEPLOY_SHARED_DIRECTORY</code></td><td>Base path and its <code>shared/</code> directory</td></tr>
            <tr><td><code>$DEPLOY_PHP</code>, <code>$DEPLOY_COMPOSER</code></td><td>The selected PHP binary and Composer</td></tr>
            <tr><td><code>$DEPLOY_COMMIT</code>, <code>$DEPLOY_BRANCH</code>, <code>$DEPLOY_TAG</code></td><td>What is being deployed</td></tr>
            <tr><td><code>$DEPLOY_COMMIT_MESSAGE</code>, <code>$DEPLOY_AUTHOR</code></td><td>Commit details</td></tr>
            <tr><td><code>$DEPLOY_ENVIRONMENT</code>, <code>$DEPLOY_MANUAL</code></td><td><code>testing</code>/<code>production</code>; <code>1</code> when started by hand</td></tr>
            <tr><td><code>$FORGE_*</code></td><td>Forge-compatible aliases (<code>FORGE_RELEASE_DIRECTORY</code>, <code>FORGE_PHP</code>, …)</td></tr>
          </tbody>
        </table>
      </details>
    </section>

    <section class="gd-card">
      <h2>GitHub token for this site</h2>
      <p class="gd-hint" style="margin-top: 0">
        {{ meta.has_default_token ? 'This site uses your default token from Settings.' : 'You have no default token yet.' }}
        Enter a token here only if this repository needs a different one.
      </p>
      <FormField
        id="gd-token"
        :label="meta.site_token ? `Replace the site token (currently ${meta.site_token})` : 'Token for this site'"
        optional
        :error="errors.site_token"
      >
        <input
          id="gd-token"
          v-model="form.site_token"
          type="password"
          autocomplete="new-password"
          placeholder="github_pat_…"
          spellcheck="false"
          @blur="check('site_token'), checkRepo()"
        />
      </FormField>
      <label v-if="meta.site_token" class="gd-check">
        <input v-model="form.clear_site_token" type="checkbox" @change="checkRepo()" />
        Remove the site token and use the default token
      </label>
    </section>

    <section v-if="isNew" class="gd-card">
      <h2>What happens when you create the site</h2>
      <ol class="gd-steps">
        <li>
          <span class="gd-step-mark">1</span>
          <div class="gd-step-body">
            <div class="gd-step-title">Access check on GitHub</div>
            <p class="gd-muted">
              We check that <template v-if="normalizedRepo"><code>{{ normalizedRepo }}</code></template><template v-else>the repository</template>
              <template v-if="form.environment === 'testing' && createBranch"> (branch <code>{{ createBranch }}</code>)</template>
              can be reached with {{ form.site_token.trim() !== '' ? 'the token entered above' : 'your default token from Settings' }}.
              If it can't, nothing is saved and the form shows why.
            </p>
          </div>
        </li>
        <li>
          <span class="gd-step-mark">2</span>
          <div class="gd-step-body">
            <div class="gd-step-title">The settings are saved</div>
            <p class="gd-muted">
              No files are created yet. <template v-if="createHost"><strong>{{ createHost }}</strong></template><template v-else>Your domain</template>
              keeps serving what it serves now, and <code>public_html</code> is never changed.
            </p>
          </div>
        </li>
        <li>
          <span class="gd-step-mark">3</span>
          <div class="gd-step-body">
            <div class="gd-step-title">First deployment</div>
            <p v-if="!form.auto_deploy" class="gd-muted">
              Automatic deployments are off, so nothing is deployed until you press <strong>Deploy</strong> on the site page.
            </p>
            <p v-else class="gd-muted">
              Within a minute,
              <template v-if="form.environment === 'testing'">
                the latest commit on <template v-if="createBranch"><code>{{ createBranch }}</code></template><template v-else>the branch</template>
              </template>
              <template v-else>the latest published GitHub release</template>
              is checked out into <template v-if="basePreview"><code>{{ basePreview }}/releases/</code></template><template v-else>a new
              release directory</template> and the deploy script runs. <code>current</code> switches to the new release only when the
              script reaches <code>$ACTIVATE_RELEASE()</code> without errors.
              <template v-if="form.environment === 'production'">If there is no release yet, it starts when you publish one.</template>
            </p>
          </div>
        </li>
        <li>
          <span class="gd-step-mark">4</span>
          <div class="gd-step-body">
            <div class="gd-step-title">Finish on the site page</div>
            <p class="gd-muted">
              You are taken to the site page. It guides you through filling in <code>.env</code> (created from
              <code>.env.example</code> on the first deployment) and changing the document root of
              <template v-if="createHost"><strong>{{ createHost }}</strong></template><template v-else>your domain</template> in DirectAdmin to
              <template v-if="docrootPreview"><code>{{ docrootPreview }}</code></template><template v-else>the path shown there</template>.
            </p>
          </div>
        </li>
      </ol>
    </section>

    <div class="gd-actions gd-actions-sticky">
      <AsyncButton type="submit" variant="primary" :busy="save.busy.value" busy-label="Checking GitHub and saving…">
        {{ isNew ? 'Create site' : 'Save changes' }}
      </AsyncButton>
      <RouterLink class="gd-btn" :to="back.to">Cancel</RouterLink>
      <span v-if="dirty" class="gd-muted gd-small">Unsaved changes · Ctrl+S saves</span>
    </div>
  </form>

  <section v-if="meta && !isNew" class="gd-card gd-danger">
    <h2>Remove site</h2>
    <p>
      Stops deployments and removes the configuration, history and logs. The deployed files in <code>{{ meta.site.base_path }}</code>
      are left untouched.
    </p>
    <AsyncButton variant="danger" icon="trash" busy-label="Removing…" :run="removeSite">Remove site…</AsyncButton>
  </section>
</template>
