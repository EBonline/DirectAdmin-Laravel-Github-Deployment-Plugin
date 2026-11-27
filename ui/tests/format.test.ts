import { describe, expect, it } from 'vitest'
import { ago, bytes, clock, duration, statusInfo, trim } from '../src/utils/format'
import { defaultBase, domainOwner, isBranch, isSha, normalizeRepo } from '../src/utils/validate'

describe('format', () => {
  it('describes how long ago something happened', () => {
    expect(ago(null, 1000)).toBe('never')
    expect(ago(998, 1000)).toBe('just now')
    expect(ago(970, 1000)).toBe('30s ago')
    expect(ago(1000 - 300, 1000)).toBe('5 min ago')
    expect(ago(10000 - 7200, 10000)).toBe('2 h ago')
    expect(ago(0.5, 3 * 86400)).toBe('2 d ago')
    expect(ago(2000, 1000)).toBe('just now') // clock skew: never "in the future"
  })

  it('formats durations and running clocks', () => {
    expect(duration(45)).toBe('45s')
    expect(duration(72)).toBe('1m 12s')
    expect(duration(3720)).toBe('1h 02m')
    expect(duration(null)).toBe('—')
    expect(clock(7)).toBe('0:07')
    expect(clock(83)).toBe('1:23')
    expect(clock(3723)).toBe('1:02:03')
  })

  it('labels statuses', () => {
    expect(statusInfo('success')).toEqual({ label: 'Deployed', tone: 'ok' })
    expect(statusInfo('failed_after_activation').tone).toBe('warn')
    expect(statusInfo(null).label).toBe('Not deployed')
  })

  it('trims text without splitting characters', () => {
    expect(trim('ünïcödé', 3)).toBe('ünï…')
    expect(trim('short', 10)).toBe('short')
    expect(bytes(512)).toBe('512 B')
    expect(bytes(65536)).toBe('64 KB')
  })
})

describe('validate (mirrors Validate.php)', () => {
  it('normalises repositories', () => {
    expect(normalizeRepo('acme/app')).toBe('acme/app')
    expect(normalizeRepo('https://github.com/acme/app.git')).toBe('acme/app')
    expect(normalizeRepo('git@github.com:acme/app.git')).toBe('acme/app')
    expect(normalizeRepo('acme')).toBeNull()
    expect(normalizeRepo('acme/..')).toBeNull()
  })

  it('checks branches and shas', () => {
    expect(isBranch('feature/x')).toBe(true)
    expect(isBranch('-bad')).toBe(false)
    expect(isBranch('a..b')).toBe(false)
    expect(isSha('a1b2c3d')).toBe(true)
    expect(isSha('A1B2C3D')).toBe(false)
  })

  it('derives the default base path like Domains.php', () => {
    const domains = ['example.com', 'shop.example.com']
    expect(domainOwner('test.shop.example.com', domains)).toBe('shop.example.com')
    expect(defaultBase('/home/u', 'example.com', domains)).toBe('/home/u/domains/example.com')
    expect(defaultBase('/home/u', 'test.example.com', domains)).toBe('/home/u/domains/example.com/apps/test')
    expect(defaultBase('/home/u', 'other.org', domains)).toBeNull()
  })
})
