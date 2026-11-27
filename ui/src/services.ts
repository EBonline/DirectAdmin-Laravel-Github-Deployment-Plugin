// App-wide singletons: the boot data from the page and the API client. Initialised once
// in main.ts (or by tests) before any component uses them.
import { Api } from './api/client'
import type { Boot } from './api/types'
import { setServerTime } from './composables/clock'
import { reportConnection, reportSessionExpired } from './composables/connection'

let bootData: Boot | null = null
let client: Api | null = null

export function initServices(boot: Boot, fetchFn?: typeof fetch): void {
  bootData = boot
  setServerTime(boot.server_time)
  client = new Api(
    { url: boot.api, csrf: boot.csrf, fetch: fetchFn },
    {
      onServerTime: setServerTime,
      onConnection: reportConnection,
      onSessionExpired: reportSessionExpired,
    },
  )
}

export function boot(): Boot {
  if (bootData === null) {
    throw new Error('initServices() was not called')
  }
  return bootData
}

export function api(): Api {
  if (client === null) {
    throw new Error('initServices() was not called')
  }
  return client
}

/** Reads the boot JSON that Shell.php embeds in the page. */
export function readBoot(doc: Document = document): Boot {
  const el = doc.getElementById('gd-boot')
  if (!el?.textContent) {
    throw new Error('Boot data missing')
  }
  return JSON.parse(el.textContent) as Boot
}
