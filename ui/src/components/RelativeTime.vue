<script setup lang="ts">
import { computed } from 'vue'
import { useNow } from '../composables/clock'
import { ago, dateTime } from '../utils/format'

const props = defineProps<{ ts: number | null | undefined; never?: string }>()
const now = useNow()
const text = computed(() => (props.ts ? ago(props.ts, now.value) : (props.never ?? 'never')))
const iso = computed(() => (props.ts ? new Date(props.ts * 1000).toISOString() : undefined))
</script>

<template>
  <time v-if="ts" :datetime="iso" :title="dateTime(ts)">{{ text }}</time>
  <span v-else>{{ text }}</span>
</template>
