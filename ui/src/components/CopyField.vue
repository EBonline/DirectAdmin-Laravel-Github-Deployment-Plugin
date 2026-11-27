<script setup lang="ts">
import { ref } from 'vue'
import { toasts } from '../composables/toasts'
import Icon from './Icon.vue'

/** `multiline` keeps the line breaks of `text`, for snippets that are pasted as a block. */
const props = defineProps<{ text: string; label?: string; multiline?: boolean }>()
const copied = ref(false)
let timer: ReturnType<typeof setTimeout> | null = null

async function copy(): Promise<void> {
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(props.text)
    } else {
      // Plain-HTTP panels have no Clipboard API; fall back to a temporary selection.
      const ta = document.createElement('textarea')
      ta.value = props.text
      ta.setAttribute('readonly', '')
      ta.style.position = 'fixed'
      ta.style.opacity = '0'
      document.body.appendChild(ta)
      ta.select()
      const ok = document.execCommand('copy')
      ta.remove()
      if (!ok) {
        throw new Error('copy failed')
      }
    }
    copied.value = true
    if (timer) {
      clearTimeout(timer)
    }
    timer = setTimeout(() => (copied.value = false), 2000)
  } catch {
    toasts.warn('Copying did not work in this browser. Select the text and copy it yourself.')
  }
}
</script>

<template>
  <div class="gd-copy" :class="{ 'gd-copy-block': multiline }">
    <code>{{ text }}</code>
    <button type="button" class="gd-btn gd-btn-sm" :aria-label="`Copy ${label ?? text}`" @click="copy">
      <Icon :name="copied ? 'check' : 'copy'" />
      <span aria-live="polite">{{ copied ? 'Copied' : 'Copy' }}</span>
    </button>
  </div>
</template>
