/**
 * Follows the colour mode of DirectAdmin's Evolution skin.
 *
 * Evolution puts `mode:dark` or `mode:light` on its <html> element and changes it live for its
 * Light/Dark/Auto setting. The plugin page can be part of the Evolution page or sit in a frame
 * inside it, so we look at our own <html> first and then at the parent page's. Without either
 * (another skin, or a cross-origin parent) we use light, like those skins.
 *
 * In a frame the document is the plugin's own, so its page background is painted too
 * (`html.gd-framed`): otherwise the browser shows the frame white behind a dark interface.
 *
 * Shell.php runs a copy of `apply(detect())` inline for the first paint, before this module loads.
 */

const DARK = 'mode:dark'
const LIGHT = 'mode:light'

function hasMode(el: Element): boolean {
  return el.classList.contains(DARK) || el.classList.contains(LIGHT)
}

function parentRoot(win: Window): HTMLElement | null {
  if (win.parent === win) {
    return null
  }
  try {
    return win.parent.document.documentElement
  } catch {
    return null // the parent page is on another origin
  }
}

/** The <html> element that carries Evolution's mode class, or null when there is none. */
export function skinRoot(win: Window = window): HTMLElement | null {
  const own = win.document.documentElement
  if (hasMode(own)) {
    return own
  }
  const parent = parentRoot(win)
  return parent && hasMode(parent) ? parent : null
}

export function isDark(win: Window = window): boolean {
  return skinRoot(win)?.classList.contains(DARK) ?? false
}

/**
 * Only writes when the class has to change. followSkinTheme observes our own <html>, and
 * classList.add() rewrites the attribute even when the class is there, which would loop forever.
 */
function setClass(el: Element, name: string, on: boolean): void {
  if (el.classList.contains(name) !== on) {
    el.classList.toggle(name, on)
  }
}

export function applyTheme(app: HTMLElement, dark: boolean, win: Window = window): void {
  setClass(app, 'gd-dark', dark)
  if (win.parent !== win) {
    const html = win.document.documentElement
    setClass(html, 'gd-framed', true)
    setClass(html, 'gd-dark', dark)
  }
}

/** Applies the skin's mode now and whenever it changes. Returns a function that stops following. */
export function followSkinTheme(app: HTMLElement, win: Window = window): () => void {
  const update = (): void => applyTheme(app, isDark(win), win)
  update()
  const observer = new MutationObserver(update)
  // Watch both pages: the mode class can be added after we start. Our own class changes do
  // not loop, because applyTheme leaves the attribute alone when nothing changes.
  for (const root of [win.document.documentElement, parentRoot(win)]) {
    if (root) {
      observer.observe(root, { attributes: true, attributeFilter: ['class'] })
    }
  }
  return () => observer.disconnect()
}
