<?php

declare(strict_types=1);

namespace GitDeploy\Tests;

use GitDeploy\Docroot;
use GitDeploy\Site;

final class DocrootTest extends TestCase
{
    private const DOMAINS = ['example.com', 'shop.example.com', 'other.org'];

    private static function site(string $hostname, string $base, string $webDir = 'public'): Site
    {
        return Site::fromArray(['hostname' => $hostname, 'base_path' => $base, 'web_dir' => $webDir]);
    }

    public function testMainDomainRuleSkipsSubdomains(): void
    {
        $rule = Docroot::httpdRule(self::site('example.com', '/home/alice/domains/example.com'), self::DOMAINS);
        self::assertSame('example.com', $rule['domain']);
        self::assertNull($rule['sub']);
        self::assertSame(
            "|*if !SUB|\n|?DOCROOT=/home/alice/domains/example.com/current/public|\n|*endif|",
            $rule['snippet'],
        );
    }

    public function testSubdomainRuleGoesInTheParentDomain(): void
    {
        $rule = Docroot::httpdRule(self::site('app.other.org', '/home/alice/domains/other.org/apps/app'), self::DOMAINS);
        self::assertSame('other.org', $rule['domain']);
        self::assertSame('app', $rule['sub']);
        self::assertSame(
            "|*if SUB=\"app\"|\n|?DOCROOT=/home/alice/domains/other.org/apps/app/current/public|\n|*endif|",
            $rule['snippet'],
        );
    }

    public function testDomainThatIsItselfASubdomainOfAnotherDomainIsAMainDomain(): void
    {
        $rule = Docroot::httpdRule(self::site('shop.example.com', '/home/alice/domains/shop.example.com'), self::DOMAINS);
        self::assertSame('shop.example.com', $rule['domain']);
        self::assertNull($rule['sub']);
    }

    public function testWwwUsesTheDomainVirtualHost(): void
    {
        $rule = Docroot::httpdRule(self::site('www.other.org', '/home/alice/domains/other.org'), self::DOMAINS);
        self::assertSame('other.org', $rule['domain']);
        self::assertNull($rule['sub']);
        self::assertStringStartsWith("|*if !SUB|\n", $rule['snippet']);
    }

    public function testWebDirDotPointsToCurrent(): void
    {
        $rule = Docroot::httpdRule(self::site('example.com', '/home/alice/domains/example.com', '.'), self::DOMAINS);
        self::assertStringContainsString("|?DOCROOT=/home/alice/domains/example.com/current|\n", $rule['snippet']);
    }

    public function testNoRuleForUnknownDomainOrUnsafePath(): void
    {
        self::assertNull(Docroot::httpdRule(self::site('example.net', '/home/alice/domains/example.net'), self::DOMAINS));
        self::assertNull(Docroot::httpdRule(self::site('example.com', '/home/alice/a|b'), self::DOMAINS));
        self::assertNull(Docroot::httpdRule(self::site('example.com', '/home/alice/`HOME`'), self::DOMAINS));
    }
}
