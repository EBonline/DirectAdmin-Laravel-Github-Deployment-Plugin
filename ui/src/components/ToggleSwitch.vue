<script setup lang="ts">
defineProps<{ modelValue: boolean; busy?: boolean; label: string; onText?: string; offText?: string }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()
</script>

<template>
  <button
    type="button"
    role="switch"
    class="gd-switch"
    :class="{ 'is-on': modelValue }"
    :aria-checked="modelValue ? 'true' : 'false'"
    :aria-label="label"
    :disabled="busy"
    :title="label"
    @click="emit('update:modelValue', !modelValue)"
  >
    <span class="gd-switch-track" aria-hidden="true"></span>
    <span v-if="busy" class="gd-spinner" aria-hidden="true"></span>
    <span>{{ modelValue ? (onText ?? 'On') : (offText ?? 'Off') }}</span>
  </button>
</template>
