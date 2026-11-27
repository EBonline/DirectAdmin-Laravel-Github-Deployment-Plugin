<script setup lang="ts">
// The last lines of a running deployment's log, for the inline progress card.
import { nextTick, ref, watch } from 'vue'
import { useLogStream } from '../composables/logstream'

const props = defineProps<{ siteId: string; logId: string }>()
const stream = useLogStream(props.siteId, props.logId, { tailLines: 14, interval: 2000 })
const pre = ref<HTMLElement | null>(null)

watch(stream.text, async () => {
  await nextTick()
  if (pre.value) {
    pre.value.scrollTop = pre.value.scrollHeight
  }
})
</script>

<template>
  <pre ref="pre" class="gd-log is-mini" aria-label="Latest log output">{{ stream.text.value || (stream.loaded.value ? 'Starting…' : 'Loading the log…') }}<span v-if="stream.running.value" class="gd-log-cursor" aria-hidden="true"></span></pre>
</template>
