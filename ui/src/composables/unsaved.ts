import { onBeforeUnmount, onMounted } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import { confirm } from './confirm'

/** Asks before leaving a page with unsaved changes (in-app navigation and closing the tab). */
export function useUnsavedGuard(isDirty: () => boolean): void {
  onBeforeRouteLeave(() => {
    if (!isDirty()) {
      return true
    }
    return confirm({
      title: 'Leave without saving?',
      message: 'You have changes on this page that are not saved yet. They will be lost.',
      confirmLabel: 'Discard changes',
      cancelLabel: 'Keep editing',
      tone: 'danger',
    })
  })

  useBeforeUnload(isDirty)
}

/** Lets the browser ask before closing or reloading the tab while there are unsaved changes. */
export function useBeforeUnload(isDirty: () => boolean): void {
  const onBeforeUnload = (e: BeforeUnloadEvent): void => {
    if (isDirty()) {
      e.preventDefault()
      e.returnValue = ''
    }
  }
  onMounted(() => window.addEventListener('beforeunload', onBeforeUnload))
  onBeforeUnmount(() => window.removeEventListener('beforeunload', onBeforeUnload))
}

/** Ctrl/Cmd+S runs `save` instead of the browser's "Save page". */
export function useSaveShortcut(save: () => void): void {
  const onKey = (e: KeyboardEvent): void => {
    if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && e.key.toLowerCase() === 's') {
      e.preventDefault()
      save()
    }
  }
  onMounted(() => window.addEventListener('keydown', onKey))
  onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
}
