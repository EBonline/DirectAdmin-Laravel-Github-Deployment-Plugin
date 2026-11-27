<script setup lang="ts">
import { toastItems, toasts } from '../composables/toasts'
import ToastItem from './ToastItem.vue'
</script>

<template>
  <div class="gd-toasts" role="region" aria-label="Notifications">
    <ToastItem v-for="t in toastItems" :key="t.id" :toast="t" @dismiss="toasts.dismiss(t.id)" />
  </div>
</template>
