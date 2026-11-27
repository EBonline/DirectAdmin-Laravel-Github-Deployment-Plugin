<script setup lang="ts">
import type { ApiError } from '../api/client'
import AsyncButton from './AsyncButton.vue'
import Icon from './Icon.vue'

defineProps<{ error: ApiError; retry?: () => Promise<unknown>; title?: string }>()
</script>

<template>
  <div class="gd-card gd-empty" role="alert">
    <Icon :name="error.isConnection ? 'wifiOff' : error.code === 'session' ? 'lock' : 'alert'" />
    <h2>{{ title ?? (error.isConnection ? "Can't reach the server" : error.code === 'session' ? 'You are logged out' : 'Something went wrong') }}</h2>
    <p>{{ error.message }}</p>
    <slot />
    <AsyncButton v-if="retry" :run="retry" busy-label="Trying again…" icon="refresh">Try again</AsyncButton>
  </div>
</template>
