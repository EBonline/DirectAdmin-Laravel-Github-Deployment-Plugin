// Client-side mirrors of plugin/src/Validate.php and Domains.php, for instant feedback.
// The server always validates again.

/** owner/repo from owner/repo, a GitHub URL or an SSH remote; null if it is not one. */
export function normalizeRepo(input: string): string | null {
  let s = input.trim()
  s = s.replace(/^(?:https?:\/\/)?(?:www\.)?github\.com[/:]/i, '')
  s = s.replace(/^git@github\.com:/i, '')
  s = s.replace(/\/+$/, '').replace(/\.git$/, '')
  if (!/^[A-Za-z0-9][A-Za-z0-9-]{0,38}\/[A-Za-z0-9._-]{1,100}$/.test(s)) {
    return null
  }
  if (s.endsWith('/.') || s.endsWith('/..')) {
    return null
  }
  return s
}

export function isBranch(branch: string): boolean {
  return (
    branch.length > 0 &&
    branch.length <= 200 &&
    /^[A-Za-z0-9._/+-]+$/.test(branch) &&
    !branch.startsWith('-') &&
    !branch.startsWith('/') &&
    !branch.endsWith('/') &&
    !branch.endsWith('.lock') &&
    !branch.includes('..') &&
    !branch.includes('//')
  )
}

export function isSha(sha: string): boolean {
  return /^[0-9a-f]{7,40}$/.test(sha)
}

export function isHostname(host: string): boolean {
  return host.length <= 253 && /^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}$/.test(host)
}

export function isToken(token: string): boolean {
  return /^[A-Za-z0-9_]{20,255}$/.test(token)
}

/** The domain that owns `host` (exact match or longest parent), if any. */
export function domainOwner(host: string, domains: string[]): string | null {
  if (domains.includes(host)) {
    return host
  }
  let best: string | null = null
  for (const d of domains) {
    if (host.endsWith(`.${d}`) && (best === null || d.length > best.length)) {
      best = d
    }
  }
  return best
}

/** ~/domains/<domain> for a domain, ~/domains/<parent>/apps/<sub> for a subdomain. */
export function defaultBase(home: string, host: string, domains: string[]): string | null {
  const owner = domainOwner(host, domains)
  if (owner === null) {
    return null
  }
  if (owner === host) {
    return `${home}/domains/${host}`
  }
  return `${home}/domains/${owner}/apps/${host.slice(0, -owner.length - 1)}`
}

/** Byte length of a string as UTF-8 (what the server limits). */
export function utf8Length(s: string): number {
  return new TextEncoder().encode(s).length
}
