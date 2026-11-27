<script setup lang="ts">
// A plain textarea tuned for scripts and .env files: monospace, Tab inserts spaces (Esc then
// Tab leaves the field), line and size counters, and a warning before the size limit.
import { computed, ref } from 'vue'
import { bytes } from '../utils/format'
import { utf8Length } from '../utils/validate'

const props = withDefaults(
  defineProps<{ modelValue: string; id: string; rows?: number; maxBytes?: number; describedby?: string; label?: string }>(),
  { rows: 16 },
)
const emit = defineEmits<{ 'update:modelValue': [value: string]; blur: [] }>()

const el = ref<HTMLTextAreaElement | null>(null)
let escaped = false

const lines = computed(() => (props.modelValue === '' ? 0 : props.modelValue.split('\n').length))
const size = computed(() => utf8Length(props.modelValue))
const sizeClass = computed(() => {
  if (!props.maxBytes) {
    return ''
  }
  if (size.value > props.maxBytes) {
    return 'is-err'
  }
  return size.value > props.maxBytes * 0.9 ? 'is-warn' : ''
})

function onKeydown(e: KeyboardEvent): void {
  if (e.key === 'Escape') {
    escaped = true
    return
  }
  if (e.key === 'Tab' && !e.shiftKey && !e.ctrlKey && !e.altKey && !e.metaKey && !escaped) {
    e.preventDefault()
    const ta = el.value
    if (!ta) {
      return
    }
    // execCommand keeps the browser's undo history; setRangeText is the fallback.
    if (!document.execCommand?.('insertText', false, '    ')) {
      ta.setRangeText('    ', ta.selectionStart, ta.selectionEnd, 'end')
      emit('update:modelValue', ta.value)
    }
    return
  }
  escaped = false
}
</script>

<template>
  <textarea
    :id="id"
    ref="el"
    class="gd-code"
    :rows="rows"
    :value="modelValue"
    spellcheck="false"
    autocapitalize="off"
    autocomplete="off"
    wrap="off"
    :aria-label="label"
    :aria-describedby="[describedby, `${id}-meta`].filter(Boolean).join(' ')"
    @input="emit('update:modelValue', ($event.target as HTMLTextAreaElement).value)"
    @keydown="onKeydown"
    @blur="emit('blur')"
  ></textarea>
  <div :id="`${id}-meta`" class="gd-editor-meta">
    <span>{{ lines }} {{ lines === 1 ? 'line' : 'lines' }} · Tab inserts spaces (Esc, Tab to leave) · Ctrl+S saves</span>
    <span v-if="maxBytes" :class="sizeClass">
      {{ bytes(size) }} of {{ bytes(maxBytes) }}<template v-if="sizeClass === 'is-err'"> — too large to save</template>
    </span>
  </div>
</template>
