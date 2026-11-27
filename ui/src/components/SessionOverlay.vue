<script setup lang="ts">
import { ref } from 'vue'
import { checkNow, connection } from '../composables/connection'
import AsyncButton from './AsyncButton.vue'

const stillOut = ref(false)

async function retry(): Promise<void> {
  stillOut.value = false
  const ok = await checkNow()
  stillOut.value = !ok && connection.sessionExpired
}
</script>

<template>
  <div v-if="connection.sessionExpired" class="gd-backdrop">
    <div class="gd-dialog" role="alertdialog" aria-modal="true" aria-labelledby="gd-session-title" aria-describedby="gd-session-msg">
      <h2 id="gd-session-title">Your DirectAdmin session has ended</h2>
      <p id="gd-session-msg">
        Log in again in a new tab, then come back here and press <strong>Continue</strong>.
        Anything you typed on this page is still here.
      </p>
      <p v-if="stillOut" class="gd-err-text">You still seem to be logged out. Log in first, then press Continue.</p>
      <div class="gd-actions">
        <button type="button" class="gd-btn" @click="connection.sessionExpired = false">Close</button>
        <a class="gd-btn" href="/" target="_blank" rel="noopener">Log in (new tab)</a>
        <AsyncButton variant="primary" busy-label="Checking…" :run="retry">Continue</AsyncButton>
      </div>
    </div>
  </div>
</template>
