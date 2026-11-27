<script setup lang="ts">
import Icon from './Icon.vue'

defineProps<{ id: string; label?: string; error?: string; hint?: string; optional?: boolean }>()
</script>

<template>
  <div class="gd-field" :class="{ 'has-error': !!error }" :data-field="id">
    <label v-if="label" :for="id">{{ label }} <span v-if="optional" class="gd-optional">(optional)</span></label>
    <slot />
    <div v-if="error" :id="`${id}-error`" class="gd-error" role="alert"><Icon name="alert" /><span>{{ error }}</span></div>
    <div v-if="hint || $slots.hint" :id="`${id}-hint`" class="gd-hint"><slot name="hint">{{ hint }}</slot></div>
  </div>
</template>
