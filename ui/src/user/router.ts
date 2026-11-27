import { createRouter, createWebHistory, type Router } from 'vue-router'
import AppLogPage from './pages/AppLogPage.vue'
import DeployRefPage from './pages/DeployRefPage.vue'
import EnvPage from './pages/EnvPage.vue'
import LogPage from './pages/LogPage.vue'
import NotFoundPage from './pages/NotFoundPage.vue'
import SiteFormPage from './pages/SiteFormPage.vue'
import SitePage from './pages/SitePage.vue'
import SettingsPage from './pages/SettingsPage.vue'
import SitesPage from './pages/SitesPage.vue'

// Every route is a real DirectAdmin URL (user/*.html all return the app shell), so
// reloading, bookmarking and opening links in a new tab all work.
export function createUserRouter(base: string): Router {
  return createRouter({
    history: createWebHistory(`${base}/`),
    routes: [
      { path: '/index.html', alias: ['/'], name: 'sites', component: SitesPage },
      { path: '/site.html', name: 'site', component: SitePage },
      { path: '/edit.html', name: 'edit', component: SiteFormPage },
      { path: '/env.html', name: 'env', component: EnvPage },
      { path: '/log.html', name: 'log', component: LogPage },
      { path: '/applog.html', name: 'app-log', component: AppLogPage },
      { path: '/ref.html', name: 'ref', component: DeployRefPage },
      { path: '/settings.html', name: 'settings', component: SettingsPage },
      { path: '/:pathMatch(.*)*', name: 'not-found', component: NotFoundPage },
    ],
    scrollBehavior: (_to, _from, saved) => saved ?? { top: 0 },
  })
}
