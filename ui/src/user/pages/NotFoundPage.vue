<script setup lang="ts">
import { RouterLink } from 'vue-router'
import Icon from '../../components/Icon.vue'
import PageHeader from '../../components/PageHeader.vue'
</script>

<template>
  <PageHeader title="Page not found" />
  <div class="gd-card gd-empty">
    <Icon name="search" />
    <p>This page does not exist.</p>
    <RouterLink class="gd-btn" :to="{ name: 'sites' }">Go to your sites</RouterLink>
  </div>
</template>
