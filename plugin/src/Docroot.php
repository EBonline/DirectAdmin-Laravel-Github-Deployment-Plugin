<?php

declare(strict_types=1);

namespace GitDeploy;

/**
 * Verifies that the domain's document root points to current/<web dir> by placing a
 * one-time file there and requesting it over HTTP(S).
 */
final class Docroot
{
    /** @return array{ok:bool, message:string, checked_at:int} */
    public static function check(Site $site): array
    {
        $dir = $site->docrootPath();
        if (!is_dir($dir)) {
            return self::result(false, "{$dir} does not exist yet. Deploy the site first.");
        }
        if (!function_exists('curl_init')) {
            return self::result(false, 'The PHP curl extension is required for this check.');
        }
        $name = 'laravel-github-deploy-check-' . bin2hex(random_bytes(8)) . '.txt';
        $token = bin2hex(random_bytes(16));
        $file = $dir . '/' . $name;
        if (@file_put_contents($file, $token) === false) {
            return self::result(false, "Could not write a test file to {$dir}.");
        }
        @chmod($file, 0644);
        $tried = [];
        $bases = ['https://' . $site->hostname, 'http://' . $site->hostname];
        $override = Env::detect()->testOverride('GIT_DEPLOY_DOCROOT_URL');
        if ($override !== null) {
            $bases = [rtrim($override, '/')];
        }
        try {
            foreach ($bases as $base) {
                [$status, $body, $err] = self::fetch($base . '/' . $name);
                if ($status === 200 && trim($body) === $token) {
                    return self::result(true, "{$site->hostname} serves files from {$dir}.");
                }
                $tried[] = strtoupper((string) parse_url($base, PHP_URL_SCHEME)) . ': ' . ($err !== '' ? $err : "HTTP {$status}");
            }
        } finally {
            @unlink($file);
        }
        return self::result(false, "{$site->hostname} does not serve files from {$dir} yet (" . implode('; ', $tried) . ').');
    }

    /**
     * The lines an administrator puts in Admin Level → Custom HTTPD Configurations → <domain>
     * (top text box) to point this hostname to the live release. That text is inserted into
     * every VirtualHost of the domain, HTTPS and subdomains included, so the assignment is
     * limited to the main domain (`!SUB`) or to this one subdomain (`SUB="name"`).
     *
     * @param list<string> $domains the user's DirectAdmin domains
     * @return array{domain:string, sub:?string, snippet:string}|null null when the hostname
     *         no longer belongs to one of the domains, or the path cannot be written as a token
     */
    public static function httpdRule(Site $site, array $domains): ?array
    {
        $owner = Domains::owner($site->hostname, $domains);
        $path = $site->docrootPath();
        // A token value ends at "|", "`" marks a token inside it, and it cannot span lines.
        if ($owner === null || $path === '' || preg_match('/[|`\r\n]/', $path) === 1) {
            return null;
        }
        $sub = $owner === $site->hostname ? null : substr($site->hostname, 0, -strlen($owner) - 1);
        if ($sub === 'www') {
            $sub = null; // DirectAdmin serves www.<domain> from the domain's own VirtualHost.
        }
        $condition = $sub === null ? '|*if !SUB|' : '|*if SUB="' . $sub . '"|';
        return [
            'domain' => $owner,
            'sub' => $sub,
            'snippet' => $condition . "\n|?DOCROOT=" . $path . "|\n|*endif|",
        ];
    }

    /** @return array{0:int,1:string,2:string} */
    private static function fetch(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            // We compare a random token, so certificate validity does not matter here.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'laravel-github-deploy-docroot-check',
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            return [0, '', curl_error($ch)];
        }
        return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) $body, ''];
    }

    private static function result(bool $ok, string $message): array
    {
        return ['ok' => $ok, 'message' => $message, 'checked_at' => time()];
    }
}
