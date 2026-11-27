<script setup lang="ts">
// The one confirmation dialog of the app (see composables/confirm.ts): modal, keeps keyboard
// focus inside, Esc or clicking outside cancels, and can require typing a name first.
import { computed, nextTick, ref, watch } from 'vue'
import { confirmState, settleConfirm } from '../composables/confirm'

const typed = ref('')
const dialog = ref<HTMLElement | null>(null)
const input = ref<HTMLInputElement | null>(null)
const cancelBtn = ref<HTMLButtonElement | null>(null)
const confirmBtn = ref<HTMLButtonElement | null>(null)
let returnFocus: HTMLElement | null = null

const o = computed(() => confirmState.options)
const canConfirm = computed(() => {
  const need = o.value?.requireText
  return !need || typed.value.trim().toLowerCase() === need.toLowerCase()
})

watch(
  () => confirmState.open,
  async (open) => {
    if (open) {
      typed.value = ''
      returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null
      await nextTick()
      ;(input.value ?? (o.value?.tone === 'danger' ? cancelBtn.value : confirmBtn.value))?.focus()
    } else {
      returnFocus?.focus?.()
      returnFocus = null
    }
  },
)

function onKeydown(e: KeyboardEvent): void {
  if (e.key === 'Escape') {
    e.preventDefault()
    settleConfirm(false)
    return
  }
  if (e.key !== 'Tab' || !dialog.value) {
    return
  }
  const items = Array.from(dialog.value.querySelectorAll<HTMLElement>('button:not([disabled]), input, a[href]'))
  const first = items[0]
  const last = items[items.length - 1]
  if (!first || !last) {
    return
  }
  if (e.shiftKey && document.activeElement === first) {
    e.preventDefault()
    last.focus()
  } else if (!e.shiftKey && document.activeElement === last) {
    e.preventDefault()
    first.focus()
  }
}

function submit(): void {
  if (canConfirm.value) {
    settleConfirm(true)
  }
}
</script>

<template>
  <div v-if="confirmState.open && o" class="gd-backdrop" @mousedown.self="settleConfirm(false)" @keydown="onKeydown">
    <div ref="dialog" class="gd-dialog" role="alertdialog" aria-modal="true" aria-labelledby="gd-confirm-title" aria-describedby="gd-confirm-msg">
      <h2 id="gd-confirm-title">{{ o.title }}</h2>
      <p v-if="o.message" id="gd-confirm-msg">{{ o.message }}</p>
      <div v-if="o.requireText" class="gd-field">
        <label for="gd-confirm-input">Type <code>{{ o.requireText }}</code> to confirm</label>
        <input id="gd-confirm-input" ref="input" v-model="typed" type="text" autocomplete="off" spellcheck="false" @keydown.enter.prevent="submit" />
      </div>
      <div class="gd-actions">
        <button ref="cancelBtn" type="button" class="gd-btn" @click="settleConfirm(false)">{{ o.cancelLabel ?? 'Cancel' }}</button>
        <button
          ref="confirmBtn"
          type="button"
          class="gd-btn"
          :class="o.tone === 'danger' ? 'gd-btn-danger gd-btn-solid' : 'gd-btn-primary'"
          :disabled="!canConfirm"
          @click="submit"
        >
          {{ o.confirmLabel ?? 'Confirm' }}
        </button>
      </div>
    </div>
  </div>
</template>
