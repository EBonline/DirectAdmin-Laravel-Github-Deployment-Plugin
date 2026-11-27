<?php

declare(strict_types=1);

namespace GitDeploy;

/** Per-site runtime state (~/.laravel-github-deploy/state/<site>.json). */
final class State
{
    public const HISTORY_LIMIT = 50;

    public const DEFAULTS = [
        'last_poll' => 0,        // unix time of the last GitHub check
        'etag' => null,          // ETag of the last check (304s are free)
        'etag_key' => null,      // repo|source the ETag belongs to
        'remote' => null,        // latest target on GitHub (commit or release)
        'poll_error' => null,    // last check error, shown in the UI
        'last_handled' => null,  // target id last deployed automatically / via "Deploy now"
        'running' => null,       // {id, started_at} while a deploy runs
        'history' => [],         // newest first
        'docroot' => null,       // {ok, message, checked_at}
    ];

    public static function read(UserData $ud, string $site): array
    {
        return array_replace(self::DEFAULTS, Store::read($ud->stateFile($site), []));
    }

    /** @param callable(array):array $fn */
    public static function update(UserData $ud, string $site, callable $fn): array
    {
        return Store::update(
            $ud->stateFile($site),
            static fn (array $s): array => $fn(array_replace(self::DEFAULTS, $s)),
        );
    }

    /** History entry of the deploy that produced $releaseId. */
    public static function entryForRelease(array $state, ?string $releaseId): ?array
    {
        if ($releaseId === null) {
            return null;
        }
        foreach ($state['history'] as $h) {
            if (($h['type'] ?? '') === 'deploy' && ($h['release'] ?? null) === $releaseId) {
                return $h;
            }
        }
        return null;
    }

    /** Latest deploy entry (not rollback). */
    public static function lastDeploy(array $state): ?array
    {
        foreach ($state['history'] as $h) {
            if (($h['type'] ?? '') === 'deploy') {
                return $h;
            }
        }
        return null;
    }
}
