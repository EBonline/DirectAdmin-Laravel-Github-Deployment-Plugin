import { afterEach, describe, expect, it } from 'vitest'
import { followSkinTheme } from '../src/theme'

const flush = (): Promise<void> => new Promise((resolve) => setTimeout(resolve, 0))

function setMode(el: HTMLElement, mode: 'light' | 'dark' | null): void {
  el.classList.remove('mode:light', 'mode:dark')
  if (mode) {
    el.classList.add(`mode:${mode}`)
  }
}

describe('followSkinTheme', () => {
  const stops: Array<() => void> = []
  afterEach(() => {
    stops.splice(0).forEach((stop) => stop())
    setMode(document.documentElement, null)
    document.documentElement.className = ''
    document.body.innerHTML = ''
  })

  it('is light without an Evolution mode class, like the other skins', () => {
    const app = document.createElement('div')
    stops.push(followSkinTheme(app))
    expect(app.classList.contains('gd-dark')).toBe(false)
  })

  it('follows Evolution on the same page and switches live, without touching its <html>', async () => {
    const html = document.documentElement
    setMode(html, 'dark')
    const app = document.createElement('div')
    stops.push(followSkinTheme(app))
    expect(app.classList.contains('gd-dark')).toBe(true)
    expect(html.classList.contains('gd-framed')).toBe(false)

    setMode(html, 'light')
    await flush()
    expect(app.classList.contains('gd-dark')).toBe(false)
  })

  it('in a frame, follows the parent page and paints the frame page in the same mode', async () => {
    setMode(document.documentElement, 'dark')
    const frame = document.createElement('iframe')
    document.body.appendChild(frame)
    const win = frame.contentWindow as Window
    const app = win.document.createElement('div')
    win.document.body.appendChild(app)

    stops.push(followSkinTheme(app, win))
    const frameHtml = win.document.documentElement
    expect(app.classList.contains('gd-dark')).toBe(true)
    expect(frameHtml.classList.contains('gd-framed')).toBe(true)
    expect(frameHtml.classList.contains('gd-dark')).toBe(true)

    setMode(document.documentElement, 'light')
    await flush()
    expect(app.classList.contains('gd-dark')).toBe(false)
    expect(frameHtml.classList.contains('gd-dark')).toBe(false)
    expect(frameHtml.classList.contains('gd-framed')).toBe(true)
  })

  it('in a frame, prefers a mode class on its own page over the parent', () => {
    setMode(document.documentElement, 'light')
    const frame = document.createElement('iframe')
    document.body.appendChild(frame)
    const win = frame.contentWindow as Window
    setMode(win.document.documentElement, 'dark')
    const app = win.document.createElement('div')

    stops.push(followSkinTheme(app, win))
    expect(app.classList.contains('gd-dark')).toBe(true)
  })
})
