<?php

declare(strict_types=1);

namespace GitDeploy;

/** Time-limited HMAC tokens for every form POST. */
final class Csrf
{
    private const TTL = 8 * 3600;

    public function __construct(private string $secretFile, private string $subject)
    {
    }

    private function secret(): string
    {
        $s = is_file($this->secretFile) ? trim((string) @file_get_contents($this->secretFile)) : '';
        if (strlen($s) < 64) {
            $s = bin2hex(random_bytes(32));
            Fs::writeAtomic($this->secretFile, $s, 0600);
        }
        return $s;
    }

    public function token(): string
    {
        $ts = (string) time();
        return $ts . '.' . hash_hmac('sha256', $this->subject . '|' . $ts, $this->secret());
    }

    public function verify(string $token): bool
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || !ctype_digit($parts[0])) {
            return false;
        }
        $age = time() - (int) $parts[0];
        if ($age < -60 || $age > self::TTL) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $this->subject . '|' . $parts[0], $this->secret()), $parts[1]);
    }
}
