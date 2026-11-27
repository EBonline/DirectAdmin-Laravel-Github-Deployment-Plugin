<?php

declare(strict_types=1);

namespace GitDeploy;

final class Proc
{
    /**
     * Run a command (no shell), merging stderr into stdout.
     *
     * @param list<string> $cmd
     * @param array<string,string>|null $env full environment (null = inherit)
     * @param callable(string):void|null $onLine called for every output line
     * @return array{0:int,1:string} exit code and (capped) output
     */
    public static function run(
        array $cmd,
        ?string $cwd = null,
        ?array $env = null,
        ?callable $onLine = null,
        int $maxOutput = 1048576,
    ): array {
        $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]];
        $proc = @proc_open($cmd, $spec, $pipes, $cwd, $env);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Could not start ' . $cmd[0]);
        }
        $output = '';
        while (($line = fgets($pipes[1])) !== false) {
            if ($onLine !== null) {
                $onLine(rtrim($line, "\r\n"));
            }
            if (strlen($output) < $maxOutput) {
                $output .= $line;
            }
        }
        fclose($pipes[1]);
        return [proc_close($proc), $output];
    }

    /**
     * Run a command and capture stdout and stderr separately.
     *
     * @param list<string> $cmd
     * @param array<string,string>|null $env
     * @return array{0:int,1:string,2:string}
     */
    public static function capture(array $cmd, ?string $cwd = null, ?array $env = null, int $max = 1048576): array
    {
        $errFile = tempnam(sys_get_temp_dir(), 'gd-err');
        if ($errFile === false) {
            throw new \RuntimeException('Could not create a temporary file');
        }
        try {
            $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']];
            $proc = @proc_open($cmd, $spec, $pipes, $cwd, $env);
            if (!is_resource($proc)) {
                throw new \RuntimeException('Could not start ' . $cmd[0]);
            }
            $out = '';
            while (!feof($pipes[1])) {
                $chunk = fread($pipes[1], 65536);
                if ($chunk === false) {
                    break;
                }
                if (strlen($out) < $max) {
                    $out .= $chunk;
                }
            }
            fclose($pipes[1]);
            $code = proc_close($proc);
            $err = (string) @file_get_contents($errFile, false, null, 0, $max);
            return [$code, $out, $err];
        } finally {
            @unlink($errFile);
        }
    }

    /** Current environment plus overrides. */
    public static function env(array $extra = []): array
    {
        $base = getenv();
        return array_merge(is_array($base) ? $base : [], $extra);
    }

    /** First existing executable among candidates. */
    public static function which(array $candidates): ?string
    {
        foreach ($candidates as $c) {
            if (is_file($c) && is_executable($c)) {
                return $c;
            }
        }
        return null;
    }
}
