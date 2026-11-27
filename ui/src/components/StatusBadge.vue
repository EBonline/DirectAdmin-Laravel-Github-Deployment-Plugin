<script setup lang="ts">
import { computed } from 'vue'
import type { DeployStatus } from '../api/types'
import { statusInfo } from '../utils/format'

const props = defineProps<{ status?: DeployStatus | null; running?: boolean; queued?: boolean }>()

const info = computed(() => {
  if (props.running) {
    return { label: 'Deploying…', tone: 'run', live: true }
  }
  if (props.queued) {
    return { label: 'Queued', tone: 'run', live: true }
  }
  return { ...statusInfo(props.status), live: false }
})
</script>

<template>
  <span class="gd-badge" :class="`gd-badge-${info.tone}`">
    <span v-if="info.live" class="gd-dot is-live" aria-hidden="true"></span>
    {{ info.label }}
  </span>
</template>
