<script setup lang="ts">
withDefaults(defineProps<{ lines?: number; title?: boolean; cards?: number }>(), { lines: 4, title: true, cards: 1 })
</script>

<template>
  <div aria-busy="true" aria-live="polite">
    <span class="gd-sr">Loading…</span>
    <div v-for="c in cards" :key="c" class="gd-card" aria-hidden="true">
      <span v-if="title" class="gd-skel gd-skel-title"></span>
      <span v-for="i in lines" :key="i" class="gd-skel" :style="{ width: `${90 - ((i * 17) % 40)}%` }"></span>
    </div>
  </div>
</template>
