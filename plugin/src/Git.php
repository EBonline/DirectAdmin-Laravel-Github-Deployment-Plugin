<?php

declare(strict_types=1);

namespace GitDeploy;

/**
 * Git operations. Authentication uses an http.extraHeader passed through GIT_CONFIG_*
 * environment variables, so the token is never written to disk or visible in argv.
 */
final class Git
{
    public function __construct(private ?string $token = null)
    {
    }

    /** @return array<string,string> */
    private function env(): array
    {
        $extra = ['GIT_TERMINAL_PROMPT' => '0', 'GIT_ASKPASS' => '', 'SSH_ASKPASS' => ''];
        if ($this->token !== null && $this->token !== '') {
            $extra['GIT_CONFIG_COUNT'] = '1';
            $extra['GIT_CONFIG_KEY_0'] = 'http.https://github.com/.extraheader';
            $extra['GIT_CONFIG_VALUE_0'] = 'AUTHORIZATION: basic ' . base64_encode('x-access-token:' . $this->token);
        }
        return Proc::env($extra);
    }

    /** @param list<string> $args */
    public function run(array $args, ?string $cwd = null): string
    {
        [$code, $out] = Proc::run(array_merge(['git'], $args), $cwd, $this->env());
        if ($code !== 0) {
            $what = $args[0] === '-C' ? ($args[2] ?? 'command') : $args[0];
            throw new \RuntimeException("git {$what} failed: " . trim($out));
        }
        return $out;
    }

    /** Create or update the bare cache of the repository (branches + tags only). */
    public function syncMirror(string $dir, string $url): void
    {
        if (!is_dir($dir . '/objects')) {
            Fs::ensureDir(dirname($dir));
            $this->run(['init', '--bare', '--quiet', $dir]);
            $this->run(['-C', $dir, 'remote', 'add', 'origin', $url]);
        } else {
            $this->run(['-C', $dir, 'remote', 'set-url', 'origin', $url]);
        }
        $this->run(['-C', $dir, 'fetch', '--quiet', '--prune', '--prune-tags', '--force', '--tags', 'origin', '+refs/heads/*:refs/heads/*']);
    }

    /** Full commit sha for a revision, or null. */
    public function resolve(string $dir, string $rev): ?string
    {
        if ($rev === '' || $rev[0] === '-') {
            return null;
        }
        [$code, $out] = Proc::run(['git', '-C', $dir, 'rev-parse', '--verify', '--quiet', $rev . '^{commit}'], null, $this->env());
        $sha = trim($out);
        return $code === 0 && preg_match('/^[0-9a-f]{40}$/', $sha) === 1 ? $sha : null;
    }

    /** @return array{author:string,message:string} */
    public function commitInfo(string $dir, string $sha): array
    {
        $out = $this->run(['-C', $dir, 'log', '-1', '--format=%an%x1f%s', $sha]);
        $parts = explode("\x1f", trim($out), 2);
        return ['author' => $parts[0], 'message' => $parts[1] ?? ''];
    }

    /** Check out $sha from the local cache into a new release directory. */
    public function cloneRelease(string $mirror, string $target, string $sha, string $originUrl): void
    {
        $this->run(['clone', '--quiet', '--no-checkout', $mirror, $target]);
        $this->run(['-C', $target, 'checkout', '--quiet', '--detach', $sha]);
        $this->run(['-C', $target, 'remote', 'set-url', 'origin', $originUrl]);
        if (is_file($target . '/.gitmodules')) {
            $this->run(['-C', $target, 'submodule', 'update', '--init', '--recursive', '--quiet']);
        }
    }

    public static function version(): ?string
    {
        try {
            [$code, $out] = Proc::run(['git', '--version']);
        } catch (\Throwable) {
            return null;
        }
        return $code === 0 && preg_match('/(\d+\.\d+(?:\.\d+)?)/', $out, $m) === 1 ? $m[1] : null;
    }
}
