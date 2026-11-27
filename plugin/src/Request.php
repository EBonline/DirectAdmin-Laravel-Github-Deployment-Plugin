<?php

declare(strict_types=1);

namespace GitDeploy;

/**
 * DirectAdmin passes GET data in QUERY_STRING and POST data in the POST environment
 * variable (sometimes HTML-entity-encoded). The Vue app sends its data as one base64url
 * JSON `payload` field so scripts and .env files survive the trip byte-for-byte.
 */
final class Request
{
    private function __construct(
        private array $query,
        private array $post,
        public readonly bool $isPost,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $query = self::parse((string) getenv('QUERY_STRING'));
        $raw = getenv('POST');
        $post = self::parse(is_string($raw) ? $raw : '');
        $method = strtoupper((string) getenv('REQUEST_METHOD'));
        return new self($query, $post, $method === 'POST' || $post !== []);
    }

    public static function fake(array $query = [], array $post = []): self
    {
        return new self($query, $post, $post !== []);
    }

    public static function parse(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        parse_str(html_entity_decode($raw, ENT_QUOTES | ENT_HTML401, 'UTF-8'), $out);
        return $out;
    }

    public function query(string $key, string $default = ''): string
    {
        $v = $this->query[$key] ?? $default;
        return is_string($v) ? $v : $default;
    }

    public function input(string $key, string $default = ''): string
    {
        $v = $this->post[$key] ?? $default;
        return is_string($v) ? $v : $default;
    }

    /** Multi-line text (deploy scripts, lists); kept separate so form code reads clearly. */
    public function text(string $key): string
    {
        return $this->input($key);
    }

    public function checkbox(string $key): bool
    {
        return in_array($this->input($key), ['1', 'on', 'yes', 'true'], true);
    }

    /**
     * The JSON body the Vue app sends as `payload` (base64url, no padding). Encoding the
     * whole body keeps scripts and .env files byte-exact whatever DirectAdmin does to the
     * POST string. Returns null when the payload is present but unreadable.
     */
    public function payload(): ?array
    {
        $raw = $this->input('payload');
        if ($raw === '') {
            return [];
        }
        return self::decodePayload($raw);
    }

    public static function decodePayload(string $raw): ?array
    {
        $b64 = strtr(trim($raw), '-_', '+/');
        $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);
        $json = base64_decode($b64, true);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /**
     * A POST request carrying $fields as form values, so form classes written for plain
     * form posts (SiteForm) work unchanged with JSON payloads.
     */
    public function withPost(array $fields): self
    {
        $post = [];
        foreach ($fields as $key => $value) {
            $post[(string) $key] = match (true) {
                is_bool($value) => $value ? '1' : '',
                is_int($value), is_float($value) => (string) $value,
                is_string($value) => $value,
                is_array($value) => implode("\n", array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $value)),
                default => '',
            };
        }
        return new self($this->query, $post, true);
    }
}
