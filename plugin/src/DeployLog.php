<?php

declare(strict_types=1);

namespace GitDeploy;

/** Append-only deploy log with a timestamp on every line. */
final class DeployLog
{
    /** @var resource */
    private $fh;

    public function __construct(string $file)
    {
        Fs::ensureDir(dirname($file));
        $fh = @fopen($file, 'ab');
        if ($fh === false) {
            throw new \RuntimeException("Could not open log {$file}");
        }
        @chmod($file, 0600);
        $this->fh = $fh;
    }

    public function line(string $text): void
    {
        foreach (explode("\n", $text) as $line) {
            // Progress bars rewrite the line with \r; keep only the final state.
            $parts = explode("\r", $line);
            fwrite($this->fh, '[' . date('H:i:s') . '] ' . end($parts) . "\n");
        }
        fflush($this->fh);
    }

    public function close(): void
    {
        if (is_resource($this->fh)) {
            fclose($this->fh);
        }
    }
}
