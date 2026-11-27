import { createApp } from 'vue'
import AdminApp from './admin/AdminApp.vue'
import BlockedApp from './BlockedApp.vue'
import { toasts } from './composables/toasts'
import { initServices, readBoot } from './services'
import './styles/app.css'
import { followSkinTheme } from './theme'
import UserApp from './user/UserApp.vue'
import { createUserRouter } from './user/router'

function start(): void {
  const root = document.getElementById('gd-app')
  if (!root) {
    return
  }
  followSkinTheme(root)
  try {
    const boot = readBoot()
    initServices(boot)
    const app =
      boot.blocked !== null
        ? createApp(BlockedApp, { blocked: boot.blocked })
        : boot.area === 'admin'
          ? createApp(AdminApp)
          : createApp(UserApp).use(createUserRouter(boot.base))
    app.config.errorHandler = (err) => {
      console.error(err)
      toasts.error(`Something went wrong on this page (${err instanceof Error ? err.message : String(err)}). Reload the page if it keeps happening.`)
    }
    app.mount(root)
    window.__gdStarted = true
  } catch (e) {
    console.error(e)
    window.__gdStarted = true
    root.innerHTML = ''
    const div = document.createElement('div')
    div.className = 'gd-alert gd-alert-err'
    div.textContent = `Laravel Github deploy could not start: ${e instanceof Error ? e.message : String(e)}. Reload the page; if this keeps happening, ask your hosting administrator.`
    root.appendChild(div)
  }
}

start()
