<?php

declare(strict_types=1);

namespace GitDeploy\Tests;

use GitDeploy\Releases;

final class ReleasesTest extends TestCase
{
    public function testLifecycle(): void
    {
        $r = new Releases($this->tmp . '/site');
        $r->ensureLayout();
        self::assertNull($r->currentId());

        $ids = ['20260101000001', '20260101000002', '20260101000003', '20260101000004', '20260101000005'];
        foreach ($ids as $id) {
            mkdir($r->path($id));
        }
        self::assertNull($r->activate('20260101000002'));
        self::assertSame('20260101000002', $r->currentId());
        self::assertSame('releases/20260101000002', readlink($this->tmp . '/site/current'));
        self::assertSame('20260101000002', $r->activate('20260101000005'));

        // Keep the newest 2, plus the protected (current + previous).
        $removed = $r->cleanup(2, ['20260101000005', '20260101000002']);
        self::assertSame(['20260101000001', '20260101000003'], $removed);
        self::assertSame(['20260101000002', '20260101000004', '20260101000005'], $r->all());
    }

    public function testNewIdIsShortAndUniqueWithinOneMinute(): void
    {
        $r = new Releases($this->tmp . '/site');
        $r->ensureLayout();
        $a = $r->newId();
        self::assertMatchesRegularExpression('/^\d{10}$/', $a);
        mkdir($r->path($a));
        $b = $r->newId();
        self::assertSame($a . '-2', $b);
        self::assertMatchesRegularExpression(Releases::ID_PATTERN, $b);
    }

    public function testNewIdSkipsIdsOfRemovedReleases(): void
    {
        // A failed deploy's folder is removed, but its history entry and log keep the id.
        $r = new Releases($this->tmp . '/site');
        $r->ensureLayout();
        $a = $r->newId();
        self::assertSame($a . '-3', $r->newId([$a, $a . '-2']));
    }

    public function testOrdersLegacyIdsFirstAndSuffixesNumerically(): void
    {
        $r = new Releases($this->tmp . '/site');
        $r->ensureLayout();
        foreach (['2611261413-10', '20261124143000', '2611261413', '2611270900', '2611261413-2', '20261125090000-2'] as $id) {
            mkdir($r->path($id));
        }
        self::assertSame(
            ['20261124143000', '20261125090000-2', '2611261413', '2611261413-2', '2611261413-10', '2611270900'],
            $r->all(),
        );
        self::assertSame(['20261124143000', '20261125090000-2'], $r->cleanup(4));
    }

    public function testRejectsInvalidIdsAndRealCurrentDirectory(): void
    {
        $r = new Releases($this->tmp . '/site');
        try {
            $r->path('../../etc');
            self::fail('Expected exception');
        } catch (\InvalidArgumentException) {
        }
        mkdir($this->tmp . '/site/current', 0755, true);
        $this->expectException(\RuntimeException::class);
        $r->ensureLayout();
    }
}
