<script setup lang="ts">
// A button for anything that talks to the server: shows a spinner and a specific
// "…ing" label while busy, ignores repeated clicks, and explains why it is disabled.
import { computed } from 'vue'
import { useTask } from '../composables/resource'
import Icon from './Icon.vue'
import type { IconName } from './icons'

const props = withDefaults(
  defineProps<{
    run?: () => Promise<unknown>
    busy?: boolean
    busyLabel?: string
    variant?: 'default' | 'primary' | 'danger' | 'danger-solid' | 'link'
    size?: 'md' | 'sm'
    type?: 'button' | 'submit'
    disabled?: boolean
    /** Why the button is disabled; shown as a tooltip and to screen readers. */
    reason?: string
    icon?: IconName
  }>(),
  { variant: 'default', size: 'md', type: 'button' },
)

const task = useTask(async () => {
  await props.run?.()
})

const isBusy = computed(() => props.busy || task.busy.value)

const classes = computed(() => {
  if (props.variant === 'link') {
    return ['gd-link']
  }
  return [
    'gd-btn',
    {
      'gd-btn-primary': props.variant === 'primary',
      'gd-btn-danger': props.variant === 'danger' || props.variant === 'danger-solid',
      'gd-btn-solid': props.variant === 'danger-solid',
      'gd-btn-sm': props.size === 'sm',
      'is-busy': isBusy.value,
    },
  ]
})

function onClick(e: MouseEvent): void {
  if (isBusy.value || props.disabled) {
    e.preventDefault()
    return
  }
  if (props.run) {
    void task.run()
  }
}
</script>

<template>
  <button
    :type="type"
    :class="classes"
    :disabled="disabled"
    :aria-busy="isBusy ? 'true' : undefined"
    :aria-disabled="isBusy ? 'true' : undefined"
    :title="disabled ? reason : undefined"
    @click="onClick"
  >
    <span v-if="isBusy" class="gd-spinner" aria-hidden="true"></span>
    <Icon v-else-if="icon" :name="icon" />
    <span v-if="isBusy && busyLabel">{{ busyLabel }}</span>
    <slot v-else />
    <span v-if="disabled && reason" class="gd-sr">({{ reason }})</span>
  </button>
</template>
