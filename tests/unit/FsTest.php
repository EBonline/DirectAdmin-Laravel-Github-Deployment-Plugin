<?php

declare(strict_types=1);

namespace GitDeploy\Tests;

use GitDeploy\Fs;

final class FsTest extends TestCase
{
    public function testNormalize(): void
    {
        self::assertSame('/home/u/x', Fs::normalize('/home//u/./x/'));
        self::assertNull(Fs::normalize('/home/u/../v'));
        self::assertNull(Fs::normalize('relative/path'));
        self::assertTrue(Fs::isWithin('/home/u/a', '/home/u'));
        self::assertTrue(Fs::isWithin('/home/u', '/home/u'));
        self::assertFalse(Fs::isWithin('/home/u2', '/home/u'));
    }

    public function testSafeRelative(): void
    {
        foreach (['public', '.env', 'storage/app', 'web/dist'] as $ok) {
            self::assertTrue(Fs::isSafeRelative($ok), $ok);
        }
        foreach (['', '/abs', '../x', 'a/../b', 'a/./b', 'a b', 'a//b'] as $bad) {
            self::assertFalse(Fs::isSafeRelative($bad), $bad);
        }
    }

    public function testAtomicSymlinkSwitchesAndRefusesRealDirectory(): void
    {
        mkdir($this->tmp . '/a');
        mkdir($this->tmp . '/b');
        Fs::atomicSymlink('a', $this->tmp . '/current');
        self::assertSame('a', readlink($this->tmp . '/current'));
        Fs::atomicSymlink('b', $this->tmp . '/current');
        self::assertSame('b', readlink($this->tmp . '/current'));
        mkdir($this->tmp . '/real');
        $this->expectException(\RuntimeException::class);
        Fs::atomicSymlink('a', $this->tmp . '/real');
    }

    public function testRemoveDoesNotFollowSymlinks(): void
    {
        mkdir($this->tmp . '/keep');
        file_put_contents($this->tmp . '/keep/file', 'x');
        mkdir($this->tmp . '/tree');
        symlink($this->tmp . '/keep', $this->tmp . '/tree/link');
        Fs::remove($this->tmp . '/tree');
        self::assertDirectoryDoesNotExist($this->tmp . '/tree');
        self::assertFileExists($this->tmp . '/keep/file');
    }
}
