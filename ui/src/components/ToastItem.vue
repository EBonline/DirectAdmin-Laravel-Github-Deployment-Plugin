<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import type { Toast } from '../composables/toasts'
import Icon from './Icon.vue'
import type { IconName } from './icons'

const props = defineProps<{ toast: Toast }>()
const emit = defineEmits<{ dismiss: [] }>()

const icon = computed<IconName>(() => ({ ok: 'circleCheck', err: 'circleX', warn: 'alert', info: 'info' })[props.toast.tone] as IconName)

// Auto-dismiss, paused while the pointer or keyboard focus is on the toast.
let remaining = props.toast.timeout
let startedAt = 0
let timer: ReturnType<typeof setTimeout> | null = null

function start(): void {
  if (remaining <= 0 || timer !== null) {
    return
  }
  startedAt = Date.now()
  timer = setTimeout(() => emit('dismiss'), remaining)
}

function pause(): void {
  if (timer === null) {
    return
  }
  clearTimeout(timer)
  timer = null
  remaining = Math.max(1500, remaining - (Date.now() - startedAt))
}

function onAction(): void {
  props.toast.action?.onClick?.()
  emit('dismiss')
}

onMounted(start)
onBeforeUnmount(() => {
  if (timer !== null) {
    clearTimeout(timer)
  }
})
</script>

<template>
  <div
    class="gd-toast"
    :class="`gd-toast-${toast.tone}`"
    :role="toast.tone === 'err' ? 'alert' : 'status'"
    @mouseenter="pause"
    @mouseleave="start"
    @focusin="pause"
    @focusout="start"
  >
    <Icon :name="icon" />
    <div class="gd-toast-body">
      <div>{{ toast.message }}</div>
      <RouterLink v-if="toast.action?.to" :to="toast.action.to" @click="emit('dismiss')">{{ toast.action.label }} →</RouterLink>
      <button v-else-if="toast.action" type="button" class="gd-link" @click="onAction">{{ toast.action.label }}</button>
    </div>
    <button type="button" class="gd-toast-close" aria-label="Dismiss notification" @click="emit('dismiss')"><Icon name="x" /></button>
  </div>
</template>
