<script setup lang="ts">
import ConfirmDialog from './ConfirmDialog.vue'
import Logo from './Logo.vue'
import ConnectionBanner from './ConnectionBanner.vue'
import SessionOverlay from './SessionOverlay.vue'
import ToastRegion from './ToastRegion.vue'
</script>

<template>
  <header class="gd-header">
    <slot name="brand">
      <span class="gd-brand"><Logo />Laravel Github deploy</span>
    </slot>
    <slot name="nav" />
  </header>
  <ConnectionBanner />
  <main>
    <slot />
  </main>
  <ToastRegion />
  <ConfirmDialog />
  <SessionOverlay />
</template>
