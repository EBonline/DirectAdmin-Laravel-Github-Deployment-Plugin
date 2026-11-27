<script setup lang="ts">
import { ref } from 'vue'
import { ApiError, toApiError } from '../../api/client'
import type { Message, UserSettings } from '../../api/types'
import Alert from '../../components/Alert.vue'
import AsyncButton from '../../components/AsyncButton.vue'
import ErrorState from '../../components/ErrorState.vue'
import FormField from '../../components/FormField.vue'
import Icon from '../../components/Icon.vue'
import PageHeader from '../../components/PageHeader.vue'
import Skeleton from '../../components/Skeleton.vue'
import { confirm } from '../../composables/confirm'
import { useResource, useTask } from '../../composables/resource'
import { toasts } from '../../composables/toasts'
import { api } from '../../services'
import { isToken } from '../../utils/validate'

const r = useResource(() => api().get<UserSettings>('settings'))
void r.refresh()

const token = ref('')
const tokenError = ref('')

const save = useTask(async () => {
  const value = token.value.trim()
  if (!isToken(value)) {
    tokenError.value = value === '' ? 'Paste your GitHub token first.' : 'This does not look like a GitHub token.'
    throw new ApiError('validation', tokenError.value)
  }
  tokenError.value = ''
  try {
    const res = await api().post<Message>('save_token', { token: value })
    toasts.success(res.message)
    token.value = ''
    await r.refresh()
  } catch (e) {
    const err = toApiError(e)
    if (err.fields.token) {
      tokenError.value = err.fields.token
    }
    throw err
  }
})

async function removeToken(): Promise<void> {
  const ok = await confirm({
    title: 'Remove your GitHub token?',
    message: 'Sites without their own token can no longer check GitHub or deploy until you add a new token.',
    confirmLabel: 'Remove token',
    tone: 'danger',
  })
  if (!ok) {
    return
  }
  const res = await api().post<Message>('remove_token')
  toasts.success(res.message)
  await r.refresh()
}
</script>

<template>
  <PageHeader title="Settings" />
  <Skeleton v-if="!r.data.value && !r.error.value" :cards="2" />
  <ErrorState v-else-if="!r.data.value && r.error.value" :error="r.error.value" :retry="r.refresh" />

  <template v-else-if="r.data.value">
    <form class="gd-card" novalidate @submit.prevent="save.run()">
      <h2>GitHub token</h2>
      <p v-if="r.data.value.has_token" class="gd-ok-text gd-inline-flex"><Icon name="circleCheck" />Token saved: <code>{{ r.data.value.token }}</code></p>
      <Alert v-else tone="warn">No token yet. The plugin needs one to see your repositories.</Alert>
      <p class="gd-hint">
        Create a
        <a href="https://github.com/settings/personal-access-tokens/new" target="_blank" rel="noopener">fine-grained personal access token <Icon name="external" /></a>
        with access to the repositories you deploy and the repository permission <strong>Contents: Read-only</strong>
        (Metadata: Read-only is added automatically). It is stored in <code>~/.laravel-github-deploy/credentials.json</code>, readable
        only by your account, and is never passed to deploy scripts.
      </p>
      <FormField id="gd-token" :label="r.data.value.has_token ? 'Replace the token' : 'Token'" :error="tokenError">
        <input id="gd-token" v-model="token" type="password" autocomplete="new-password" placeholder="github_pat_…" spellcheck="false" />
      </FormField>
      <div class="gd-actions">
        <AsyncButton type="submit" variant="primary" icon="key" :busy="save.busy.value" busy-label="Checking with GitHub…">Save token</AsyncButton>
        <AsyncButton v-if="r.data.value.has_token" variant="danger" icon="trash" busy-label="Removing…" :run="removeToken">Remove token</AsyncButton>
      </div>
    </form>

    <section class="gd-card">
      <h2>Server settings</h2>
      <dl class="gd-dl">
        <dt>GitHub is checked every</dt>
        <dd>{{ r.data.value.server.poll_interval }} minutes</dd>
        <dt>A deployment may take</dt>
        <dd>up to {{ r.data.value.server.deploy_timeout }} minutes</dd>
        <dt>Releases a site may keep</dt>
        <dd>up to {{ r.data.value.server.max_keep_releases }}</dd>
        <dt>PHP versions</dt>
        <dd>{{ r.data.value.php_versions.map((v) => v.label).join(', ') }}</dd>
        <dt>Composer</dt>
        <dd><code v-if="r.data.value.composer">{{ r.data.value.composer }}</code><span v-else>not installed on this server</span></dd>
      </dl>
      <p class="gd-hint">These are set by your hosting administrator.</p>
    </section>
  </template>
</template>
