// Talks to api.raw. Reads are GET requests; writes are POST requests with the CSRF token
// and the data as one base64url JSON `payload` field, which survives DirectAdmin's handling
// of POST data byte-for-byte. Every failure becomes an ApiError with a code the UI can act on.

export type FieldErrors = Record<string, string>

export class ApiError extends Error {
  constructor(
    public readonly code: string,
    message: string,
    public readonly status = 0,
    public readonly fields: FieldErrors = {},
  ) {
    super(message)
    this.name = 'ApiError'
  }

  /** The server could not be reached at all (as opposed to answering with an error). */
  get isConnection(): boolean {
    return this.code === 'network' || this.code === 'timeout'
  }
}

export function toApiError(e: unknown): ApiError {
  if (e instanceof ApiError) {
    return e
  }
  return new ApiError('client', e instanceof Error ? e.message : String(e))
}

export interface ApiHooks {
  /** Server clock (unix seconds) from every response, to keep relative times correct. */
  onServerTime?(serverTime: number): void
  /** true after any answer from the server, false when it could not be reached. */
  onConnection?(ok: boolean): void
  /** The DirectAdmin session ended (the answer was a login page instead of JSON). */
  onSessionExpired?(): void
}

export interface ApiConfig {
  url: string
  csrf: string
  timeoutMs?: number
  fetch?: typeof fetch
}

type Query = Record<string, string | number | undefined | null>

/** UTF-8 safe base64url (no padding) of the JSON encoding of `data`. */
export function encodePayload(data: unknown): string {
  const bytes = new TextEncoder().encode(JSON.stringify(data ?? {}))
  let bin = ''
  for (let i = 0; i < bytes.length; i += 0x8000) {
    bin += String.fromCharCode(...bytes.subarray(i, i + 0x8000))
  }
  return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

export class Api {
  private csrf: string
  private readonly timeoutMs: number
  private readonly fetchFn: typeof fetch

  constructor(
    private readonly config: ApiConfig,
    private readonly hooks: ApiHooks = {},
  ) {
    this.csrf = config.csrf
    this.timeoutMs = config.timeoutMs ?? 30000
    this.fetchFn = config.fetch ?? ((input, init) => fetch(input, init))
  }

  get<T>(action: string, query: Query = {}): Promise<T> {
    return this.request<T>('GET', action, query)
  }

  post<T>(action: string, payload: object = {}): Promise<T> {
    return this.request<T>('POST', action, {}, payload)
  }

  /** Asks for a fresh CSRF token; also a cheap way to see whether the server is reachable. */
  async refreshCsrf(): Promise<void> {
    const r = await this.request<{ csrf: string }>('GET', 'csrf')
    this.csrf = r.csrf
  }

  private async request<T>(method: 'GET' | 'POST', action: string, query: Query = {}, payload?: object, retried = false): Promise<T> {
    const params = new URLSearchParams({ action })
    for (const [k, v] of Object.entries(query)) {
      if (v !== undefined && v !== null) {
        params.set(k, String(v))
      }
    }
    const init: RequestInit = { method, credentials: 'same-origin', cache: 'no-store' }
    if (method === 'POST') {
      init.headers = { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }
      init.body = new URLSearchParams({ csrf: this.csrf, payload: encodePayload(payload) }).toString()
    }
    const controller = new AbortController()
    const timer = setTimeout(() => controller.abort(), this.timeoutMs)
    init.signal = controller.signal

    let res: Response
    let text: string
    try {
      res = await this.fetchFn(`${this.config.url}?${params.toString()}`, init)
      text = await res.text()
    } catch (e) {
      this.hooks.onConnection?.(false)
      if (controller.signal.aborted) {
        throw new ApiError('timeout', 'The server took too long to answer. Check your connection and try again.')
      }
      throw new ApiError('network', "Can't reach the server. Check your connection and try again.")
    } finally {
      clearTimeout(timer)
    }

    let data: unknown
    try {
      data = JSON.parse(text)
    } catch {
      this.hooks.onConnection?.(true)
      if (/<html|<form|login/i.test(text) || res.redirected) {
        this.hooks.onSessionExpired?.()
        throw new ApiError('session', 'Your DirectAdmin session has ended. Log in again to continue.', res.status)
      }
      throw new ApiError('bad_response', `The server sent an unexpected answer (HTTP ${res.status}). Reload the page and try again.`, res.status)
    }
    this.hooks.onConnection?.(true)

    const body = (data ?? {}) as { ok?: boolean; server_time?: number; error?: { code?: string; message?: string; fields?: FieldErrors } }
    if (typeof body.server_time === 'number') {
      this.hooks.onServerTime?.(body.server_time)
    }
    if (body.ok === true) {
      return body as T
    }
    const err = body.error ?? {}
    if (err.code === 'csrf' && method === 'POST' && !retried) {
      // The form token expired (they live 8 hours). Get a new one and try once more.
      await this.refreshCsrf()
      return this.request<T>(method, action, query, payload, true)
    }
    throw new ApiError(err.code ?? 'server', err.message ?? `Request failed (HTTP ${res.status}).`, res.status, err.fields ?? {})
  }
}
