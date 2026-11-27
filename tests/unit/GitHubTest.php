<?php

declare(strict_types=1);

namespace GitDeploy\Tests;

use GitDeploy\GitHub;
use GitDeploy\GitHubException;

final class GitHubTest extends TestCase
{
    public function testTargetMapping(): void
    {
        $c = GitHub::commitTarget([
            'sha' => str_repeat('a', 40),
            'html_url' => 'https://github.com/acme/app/commit/aaa',
            'commit' => ['message' => "Fix login\n\nDetails", 'author' => ['name' => 'Ada'], 'committer' => ['date' => '2026-11-24T10:00:00Z']],
        ], 'main');
        self::assertSame(str_repeat('a', 40), $c['id']);
        self::assertSame('aaaaaaa', $c['label']);
        self::assertSame('Fix login', $c['message']);
        self::assertSame('Ada', $c['author']);
        self::assertSame('main', $c['ref']);

        $r = GitHub::releaseTarget(['tag_name' => 'v1.2.0', 'name' => '', 'prerelease' => true, 'author' => ['login' => 'ada']]);
        self::assertSame('v1.2.0', $r['id']);
        self::assertSame('v1.2.0', $r['message']);
        self::assertTrue($r['prerelease']);
        self::assertNull($r['sha']);
    }

    public function testMissingTokenIsReported(): void
    {
        $this->expectException(GitHubException::class);
        (new GitHub('https://api.github.com', null))->get('/rate_limit');
    }
}
