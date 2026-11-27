<script setup lang="ts">
// Quiet indicator that a page keeps itself up to date: "Updated 4s ago", or a spinner
// while a background refresh runs.
import { computed } from 'vue'
import { useNow } from '../composables/clock'

const props = defineProps<{ at: number | null; loading?: boolean }>()
const now = useNow()
const seconds = computed(() => {
  void now.value
  return props.at ? Math.max(0, Math.floor((Date.now() - props.at) / 1000)) : null
})
const text = computed(() => {
  const s = seconds.value
  if (s === null) {
    return ''
  }
  if (s < 5) {
    return 'Up to date'
  }
  return s < 60 ? `Updated ${s}s ago` : `Updated ${Math.floor(s / 60)} min ago`
})
</script>

<template>
  <span v-if="at" class="gd-updated gd-hide-sm" aria-live="off">
    <span v-if="loading" class="gd-spinner" aria-hidden="true"></span>
    {{ text }}
  </span>
</template>
