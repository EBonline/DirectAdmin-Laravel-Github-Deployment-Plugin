<script setup lang="ts">
import type { Environment } from '../api/types'

defineProps<{ environment: Environment }>()
</script>

<template>
  <span class="gd-env" :class="`gd-env-${environment}`" :title="environment === 'production' ? 'Deploys GitHub releases' : 'Deploys every commit on a branch'">
    {{ environment === 'production' ? 'Production' : 'Testing' }}
  </span>
</template>
