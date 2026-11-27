<?php

declare(strict_types=1);

namespace GitDeploy;

/** GitHub tokens: a user default plus optional per-site overrides (credentials.json, 0600). */
final class Credentials
{
    public function __construct(private UserData $ud)
    {
    }

    private function data(): array
    {
        $d = Store::read($this->ud->credentialsFile(), []);
        return [
            'default' => is_string($d['default'] ?? null) ? $d['default'] : null,
            'sites' => is_array($d['sites'] ?? null) ? $d['sites'] : [],
        ];
    }

    public function defaultToken(): ?string
    {
        return $this->data()['default'];
    }

    public function siteToken(string $siteId): ?string
    {
        $t = $this->data()['sites'][$siteId] ?? null;
        return is_string($t) && $t !== '' ? $t : null;
    }

    public function tokenFor(string $siteId): ?string
    {
        return $this->siteToken($siteId) ?? $this->defaultToken();
    }

    public function setDefault(?string $token): void
    {
        $this->update(static function (array $d) use ($token): array {
            $d['default'] = $token;
            return $d;
        });
    }

    public function setSite(string $siteId, ?string $token): void
    {
        $this->update(static function (array $d) use ($siteId, $token): array {
            if ($token === null) {
                unset($d['sites'][$siteId]);
            } else {
                $d['sites'][$siteId] = $token;
            }
            return $d;
        });
    }

    private function update(callable $fn): void
    {
        Store::update($this->ud->credentialsFile(), fn (array $raw): array => $fn($this->normalize($raw)), [], 0600);
    }

    private function normalize(array $d): array
    {
        return [
            'default' => is_string($d['default'] ?? null) ? $d['default'] : null,
            'sites' => is_array($d['sites'] ?? null) ? $d['sites'] : [],
        ];
    }

    public static function mask(?string $token): string
    {
        if ($token === null || $token === '') {
            return 'not set';
        }
        $prefix = str_starts_with($token, 'github_pat_') ? 'github_pat_' : substr($token, 0, 4);
        return $prefix . '…' . substr($token, -4);
    }
}
