<script setup lang="ts">
// Full deployment log that follows a running deployment: sticks to the bottom unless you
// scroll up (then offers "Jump to latest"), and can be copied or downloaded.
import { nextTick, onMounted, ref, watch } from 'vue'
import { useLogStream } from '../composables/logstream'
import { toasts } from '../composables/toasts'
import Alert from './Alert.vue'
import Icon from './Icon.vue'

const props = defineProps<{ siteId: string; logId: string; stream: ReturnType<typeof useLogStream> }>()

const pre = ref<HTMLElement | null>(null)
const stick = ref(true)

function atBottom(el: HTMLElement): boolean {
  return el.scrollTop + el.clientHeight >= el.scrollHeight - 24
}

function onScroll(): void {
  if (pre.value) {
    stick.value = atBottom(pre.value)
  }
}

function jump(): void {
  if (pre.value) {
    pre.value.scrollTop = pre.value.scrollHeight
    stick.value = true
  }
}

watch(
  () => props.stream.text.value,
  async () => {
    if (stick.value) {
      await nextTick()
      jump()
    }
  },
)
onMounted(jump)

async function copyLog(): Promise<void> {
  try {
    await navigator.clipboard.writeText(props.stream.text.value)
    toasts.success('Log copied to the clipboard.')
  } catch {
    toasts.warn('Copying did not work in this browser. Use Download instead.')
  }
}

function download(): void {
  const blob = new Blob([props.stream.text.value], { type: 'text/plain;charset=utf-8' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = `${props.siteId}-${props.logId}.log`
  a.click()
  setTimeout(() => URL.revokeObjectURL(a.href), 1000)
}
</script>

<template>
  <div>
    <div class="gd-toolbar">
      <span class="gd-muted gd-small">
        <template v-if="stream.running.value"><span class="gd-spinner" aria-hidden="true"></span> Following the live output (updates every 1.5 s)</template>
        <template v-else-if="stream.truncated.value">Showing the last 512 KB of the log.</template>
      </span>
      <div class="gd-btn-row">
        <button type="button" class="gd-btn gd-btn-sm" :disabled="!stream.text.value" @click="copyLog"><Icon name="copy" />Copy</button>
        <button type="button" class="gd-btn gd-btn-sm" :disabled="!stream.text.value" @click="download"><Icon name="download" />Download</button>
      </div>
    </div>
    <Alert v-if="stream.error.value && stream.loaded.value" tone="warn">
      Could not load the newest log output: {{ stream.error.value.message }} It will be retried automatically.
    </Alert>
    <div class="gd-log-wrap">
      <pre ref="pre" class="gd-log" tabindex="0" aria-label="Deployment log" @scroll="onScroll">{{ stream.text.value
      }}<template v-if="!stream.text.value && !stream.running.value">{{ stream.loaded.value ? 'No log output.' : 'Loading…' }}</template><span v-if="stream.running.value" class="gd-log-cursor" aria-hidden="true"></span></pre>
      <button v-if="!stick && stream.running.value" type="button" class="gd-btn gd-btn-sm gd-jump" @click="jump"><Icon name="arrowDown" />Jump to latest</button>
    </div>
  </div>
</template>
