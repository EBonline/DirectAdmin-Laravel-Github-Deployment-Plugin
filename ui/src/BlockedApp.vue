<script setup lang="ts">
import type { Blocked } from './api/types'
import AppShell from './components/AppShell.vue'
import BlockedPage from './components/BlockedPage.vue'

defineProps<{ blocked: Blocked }>()
</script>

<template>
  <AppShell>
    <BlockedPage :blocked="blocked" />
  </AppShell>
</template>
