<?php

declare(strict_types=1);

namespace GitDeploy;

/** The user's DirectAdmin domains, read from ~/domains. */
final class Domains
{
    /** @return list<string> */
    public static function forHome(string $home): array
    {
        $out = [];
        foreach (@scandir($home . '/domains') ?: [] as $entry) {
            if (Validate::hostname($entry) && is_dir($home . '/domains/' . $entry)) {
                $out[] = $entry;
            }
        }
        sort($out);
        return $out;
    }

    /** The domain that owns $hostname (exact match or longest parent), if any. */
    public static function owner(string $hostname, array $domains): ?string
    {
        if (in_array($hostname, $domains, true)) {
            return $hostname;
        }
        $best = null;
        foreach ($domains as $d) {
            if (str_ends_with($hostname, '.' . $d) && ($best === null || strlen($d) > strlen($best))) {
                $best = $d;
            }
        }
        return $best;
    }

    /** ~/domains/<domain> for a domain, ~/domains/<parent>/apps/<sub> for a subdomain. */
    public static function defaultBase(string $home, string $hostname, array $domains): ?string
    {
        $owner = self::owner($hostname, $domains);
        if ($owner === null) {
            return null;
        }
        if ($owner === $hostname) {
            return $home . '/domains/' . $hostname;
        }
        $sub = substr($hostname, 0, -strlen($owner) - 1);
        return $home . '/domains/' . $owner . '/apps/' . $sub;
    }
}
