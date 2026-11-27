<script setup lang="ts">
import { computed } from 'vue'
import Icon from './Icon.vue'
import type { IconName } from './icons'

const props = defineProps<{ tone: 'ok' | 'err' | 'warn' | 'info' | 'run'; icon?: IconName | null }>()
const iconName = computed<IconName | null>(() => {
  if (props.icon !== undefined) {
    return props.icon
  }
  return { ok: 'circleCheck', err: 'alert', warn: 'alert', info: 'info', run: 'info' }[props.tone] as IconName
})
</script>

<template>
  <div class="gd-alert" :class="`gd-alert-${tone}`" :role="tone === 'err' ? 'alert' : 'status'">
    <Icon v-if="iconName" :name="iconName" />
    <div class="gd-alert-body"><slot /></div>
  </div>
</template>
