<script setup lang="ts">
// Page title with optional back link and actions. The heading receives focus after
// navigation (see AppShell) so screen readers announce the new page.
import { RouterLink, type RouteLocationRaw } from 'vue-router'
import Icon from './Icon.vue'

defineProps<{ title: string; back?: { to: RouteLocationRaw; label: string } }>()
</script>

<template>
  <div class="gd-page-head">
    <div>
      <RouterLink v-if="back" :to="back.to" class="gd-back"><Icon name="arrowLeft" />{{ back.label }}</RouterLink>
      <h1 tabindex="-1" data-page-title>{{ title }}<slot name="badges" /></h1>
      <div v-if="$slots.sub" class="gd-page-sub"><slot name="sub" /></div>
    </div>
    <div v-if="$slots.actions" class="gd-btn-row"><slot name="actions" /></div>
  </div>
</template>
