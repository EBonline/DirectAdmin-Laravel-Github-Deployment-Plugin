<script setup lang="ts">
// Small inline stroke icons (no icon font or image requests).
import { PATHS, type IconName } from './icons'


defineProps<{ name: IconName; label?: string }>()
</script>

<template>
  <svg class="gd-icon" viewBox="0 0 24 24" :role="label ? 'img' : undefined" :aria-label="label" :aria-hidden="label ? undefined : 'true'">
    <path :d="PATHS[name]" />
  </svg>
</template>
