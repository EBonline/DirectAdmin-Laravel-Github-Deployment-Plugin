<?php

declare(strict_types=1);

namespace GitDeploy;

final class Validate
{
    /** Accepts owner/repo, https://github.com/owner/repo(.git) or git@github.com:owner/repo.git. */
    public static function repo(string $input): ?string
    {
        $s = trim($input);
        $s = preg_replace('~^(?:https?://)?(?:www\.)?github\.com[/:]~i', '', $s) ?? $s;
        $s = preg_replace('~^git@github\.com:~i', '', $s) ?? $s;
        $s = preg_replace('~\.git$~', '', rtrim($s, '/')) ?? $s;
        if (preg_match('~^([A-Za-z0-9](?:[A-Za-z0-9-]{0,38}))/([A-Za-z0-9._-]{1,100})$~', $s) !== 1) {
            return null;
        }
        if (str_ends_with($s, '/.') || str_ends_with($s, '/..')) {
            return null;
        }
        return $s;
    }

    public static function branch(string $branch): bool
    {
        return strlen($branch) <= 200
            && preg_match('~^[A-Za-z0-9._/+-]+$~', $branch) === 1
            && !str_starts_with($branch, '-')
            && !str_starts_with($branch, '/')
            && !str_ends_with($branch, '/')
            && !str_ends_with($branch, '.lock')
            && !str_contains($branch, '..')
            && !str_contains($branch, '//');
    }

    public static function tag(string $tag): bool
    {
        return self::branch($tag);
    }

    public static function sha(string $sha): bool
    {
        return preg_match('/^[0-9a-f]{7,40}$/', $sha) === 1;
    }

    public static function hostname(string $host): bool
    {
        return strlen($host) <= 253
            && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}$/', $host) === 1;
    }

    /** Fine-grained (github_pat_…) and classic (ghp_…) tokens. */
    public static function token(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_]{20,255}$/', $token) === 1;
    }
}
