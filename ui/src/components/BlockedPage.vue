<script setup lang="ts">
import type { Blocked } from '../api/types'
import Icon from './Icon.vue'

defineProps<{ blocked: Blocked }>()
</script>

<template>
  <div class="gd-card gd-empty">
    <Icon name="lock" />
    <h2>{{ blocked.title }}</h2>
    <p>{{ blocked.message }}</p>
  </div>
</template>
