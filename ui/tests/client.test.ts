import { describe, expect, it, vi } from 'vitest'
import { Api, ApiError, encodePayload } from '../src/api/client'

function decodePayload(b64url: string): unknown {
  const b64 = b64url.replace(/-/g, '+').replace(/_/g, '/')
  const bin = atob(b64 + '='.repeat((4 - (b64.length % 4)) % 4))
  return JSON.parse(new TextDecoder().decode(Uint8Array.from(bin, (c) => c.charCodeAt(0))))
}

function reply(body: unknown, status = 200): Response {
  return new Response(typeof body === 'string' ? body : JSON.stringify(body), { status })
}

describe('encodePayload', () => {
  it('round-trips non-ASCII text, quotes and shell characters as base64url', () => {
    const data = { script: 'echo "a & b < c" \'q\' %2B $HOME\n✓ ü', n: 1 }
    const encoded = encodePayload(data)
    expect(encoded).toMatch(/^[A-Za-z0-9_-]+$/)
    expect(decodePayload(encoded)).toEqual(data)
  })
})

describe('Api', () => {
  it('sends GET reads with the action in the query string', async () => {
    const fetchFn = vi.fn(async (_url: string, _init?: RequestInit) => reply({ ok: true, value: 1, server_time: 123 }))
    const onServerTime = vi.fn()
    const api = new Api({ url: '/api.raw', csrf: 't', fetch: fetchFn as unknown as typeof fetch }, { onServerTime })
    await expect(api.get('site', { site: 'a', offset: undefined })).resolves.toMatchObject({ value: 1 })
    expect(fetchFn.mock.calls[0]?.[0]).toBe('/api.raw?action=site&site=a')
    expect(onServerTime).toHaveBeenCalledWith(123)
  })

  it('posts the CSRF token and the payload as form fields', async () => {
    const fetchFn = vi.fn(async (_url: string, _init?: RequestInit) => reply({ ok: true }))
    const api = new Api({ url: '/api.raw', csrf: 'tok', fetch: fetchFn as unknown as typeof fetch })
    await api.post('save_env', { content: 'A=ü' })
    const init = fetchFn.mock.calls[0]?.[1] as RequestInit
    const body = new URLSearchParams(String(init.body))
    expect(init.method).toBe('POST')
    expect(body.get('csrf')).toBe('tok')
    expect(decodePayload(body.get('payload') ?? '')).toEqual({ content: 'A=ü' })
  })

  it('turns error answers into ApiErrors with field messages', async () => {
    const fetchFn = vi.fn(async () => reply({ ok: false, error: { code: 'validation', message: 'Fix it', fields: { repo: 'Bad' } } }, 422))
    const api = new Api({ url: '/api.raw', csrf: 't', fetch: fetchFn as unknown as typeof fetch })
    const err = await api.post('save_site').catch((e: unknown) => e)
    expect(err).toBeInstanceOf(ApiError)
    expect(err).toMatchObject({ code: 'validation', status: 422, fields: { repo: 'Bad' } })
  })

  it('refreshes an expired CSRF token once and retries', async () => {
    const fetchFn = vi
      .fn()
      .mockResolvedValueOnce(reply({ ok: false, error: { code: 'csrf', message: 'expired' } }, 403))
      .mockResolvedValueOnce(reply({ ok: true, csrf: 'fresh' }))
      .mockResolvedValueOnce(reply({ ok: true, done: true }))
    const api = new Api({ url: '/api.raw', csrf: 'old', fetch: fetchFn as unknown as typeof fetch })
    await expect(api.post('deploy', { site: 'a' })).resolves.toMatchObject({ done: true })
    const retry = new URLSearchParams(String((fetchFn.mock.calls[2]?.[1] as RequestInit).body))
    expect(retry.get('csrf')).toBe('fresh')
  })

  it('does not retry a CSRF failure forever', async () => {
    const fetchFn = vi.fn(async (url: string) =>
      url.includes('action=csrf') ? reply({ ok: true, csrf: 'x' }) : reply({ ok: false, error: { code: 'csrf', message: 'expired' } }, 403),
    )
    const api = new Api({ url: '/api.raw', csrf: 'old', fetch: fetchFn as unknown as typeof fetch })
    await expect(api.post('deploy')).rejects.toMatchObject({ code: 'csrf' })
    expect(fetchFn).toHaveBeenCalledTimes(3)
  })

  it('recognises a login page as an expired session', async () => {
    const onSessionExpired = vi.fn()
    const fetchFn = vi.fn(async () => reply('<html><form action="/CMD_LOGIN">login</form></html>'))
    const api = new Api({ url: '/api.raw', csrf: 't', fetch: fetchFn as unknown as typeof fetch }, { onSessionExpired })
    await expect(api.get('overview')).rejects.toMatchObject({ code: 'session' })
    expect(onSessionExpired).toHaveBeenCalled()
  })

  it('reports network failures and timeouts as connection problems', async () => {
    const onConnection = vi.fn()
    const failing = new Api({ url: '/api.raw', csrf: 't', fetch: (async () => Promise.reject(new TypeError('Failed to fetch'))) as typeof fetch }, { onConnection })
    const err = (await failing.get('overview').catch((e: unknown) => e)) as ApiError
    expect(err.code).toBe('network')
    expect(err.isConnection).toBe(true)
    expect(onConnection).toHaveBeenLastCalledWith(false)

    const slow = new Api({
      url: '/api.raw',
      csrf: 't',
      timeoutMs: 20,
      fetch: ((_u: string, init?: RequestInit) =>
        new Promise((_resolve, reject) => init?.signal?.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError'))))) as typeof fetch,
    })
    await expect(slow.get('overview')).rejects.toMatchObject({ code: 'timeout' })
  })
})
