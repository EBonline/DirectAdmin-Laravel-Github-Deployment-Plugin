<script setup lang="ts">
import { computed } from 'vue'
import { useNow } from '../composables/clock'
import { checkNow, connection } from '../composables/connection'
import AsyncButton from './AsyncButton.vue'
import Icon from './Icon.vue'

const now = useNow()
const seconds = computed(() => {
  void now.value // re-evaluate every second
  return Math.max(0, Math.ceil((connection.retryAt - Date.now()) / 1000))
})
</script>

<template>
  <div v-if="connection.down" class="gd-banner" role="alert">
    <Icon name="wifiOff" />
    <span>
      <strong>Can't reach the server.</strong>
      <template v-if="connection.checking"> Checking…</template>
      <template v-else> Trying again in {{ seconds }}s. What you see may be out of date; nothing you entered is lost.</template>
    </span>
    <AsyncButton size="sm" :busy="connection.checking" busy-label="Checking…" :run="checkNow" icon="refresh">Retry now</AsyncButton>
  </div>
</template>
