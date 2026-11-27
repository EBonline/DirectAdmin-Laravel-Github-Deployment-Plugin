<script setup lang="ts">
import { computed, nextTick } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import AppShell from '../components/AppShell.vue'
import Logo from '../components/Logo.vue'

const route = useRoute()
const router = useRouter()
const section = computed(() => (route.name === 'settings' ? 'settings' : 'sites'))

// After navigating, move focus to the new page's heading so screen readers announce it.
let first = true
router.afterEach(async () => {
  if (first) {
    first = false
    return
  }
  await nextTick()
  setTimeout(() => document.querySelector<HTMLElement>('[data-page-title]')?.focus({ preventScroll: true }), 0)
})
</script>

<template>
  <AppShell>
    <template #brand>
      <RouterLink class="gd-brand" :to="{ name: 'sites' }"><Logo />Laravel Github deploy</RouterLink>
    </template>
    <template #nav>
      <nav class="gd-nav" aria-label="Laravel Github deploy">
        <RouterLink :to="{ name: 'sites' }" :class="{ 'is-active': section === 'sites' }" :aria-current="section === 'sites' ? 'page' : undefined">Sites</RouterLink>
        <RouterLink :to="{ name: 'settings' }" :class="{ 'is-active': section === 'settings' }" :aria-current="section === 'settings' ? 'page' : undefined">Settings</RouterLink>
      </nav>
    </template>
    <RouterView v-slot="{ Component, route: r }">
      <component :is="Component" :key="r.fullPath" />
    </RouterView>
  </AppShell>
</template>
