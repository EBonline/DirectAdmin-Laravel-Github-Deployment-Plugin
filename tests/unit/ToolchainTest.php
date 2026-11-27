<?php

declare(strict_types=1);

namespace GitDeploy\Tests;

use GitDeploy\Toolchain;

final class ToolchainTest extends TestCase
{
    private function tool(string $dir, string $name, string $body, int $mode = 0755): string
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $file = $dir . '/' . $name;
        file_put_contents($file, "#!/bin/sh\n" . $body . "\n");
        chmod($file, $mode);
        return $file;
    }

    public function testDeployPathStartsWithTheSelectedPhp(): void
    {
        self::assertSame(
            '/usr/local/php83/bin:/home/u/.local/bin:/home/u/bin:/usr/local/bin:/usr/bin:/bin',
            Toolchain::deployPath('/usr/local/php83/bin/php', '/home/u'),
        );
    }

    public function testDeployPathPutsTheAdminOverrideBeforeTheUsersDirectories(): void
    {
        self::assertSame(
            '/usr/local/php83/bin:/home/u/.laravel-github-deploy/node-bin:/home/u/.local/bin:/home/u/bin:/usr/local/bin:/usr/bin:/bin',
            Toolchain::deployPath('/usr/local/php83/bin/php', '/home/u', '/home/u/.laravel-github-deploy/node-bin'),
        );
    }

    public function testConfiguredFindsNpmAndNpxNextToTheNodeBinary(): void
    {
        self::assertSame(['node' => null, 'npm' => null, 'npx' => null], Toolchain::configured(['node_path' => '', 'npm_path' => '']));

        $bin = $this->tmp . '/opt/bin';
        $node = $this->tool($bin, 'node-22', 'echo v22.5.0');
        $npm = $this->tool($bin, 'npm-22', 'echo 10.8.0');
        $npx = $this->tool($bin, 'npx-22', 'echo 10.8.0');
        self::assertSame(['node' => $node, 'npm' => $npm, 'npx' => $npx], Toolchain::configured(['node_path' => $node]), 'npm-22 and npx-22 go with node-22');

        unlink($npm);
        $plainNpm = $this->tool($bin, 'npm', 'echo 10.8.0');
        self::assertSame($plainNpm, Toolchain::configured(['node_path' => $node])['npm'], 'else plain npm in the same directory');

        $other = $this->tool($this->tmp . '/other', 'npm', 'echo 9.0.0');
        $cfg = Toolchain::configured(['node_path' => $node, 'npm_path' => $other]);
        self::assertSame($other, $cfg['npm'], 'an explicit npm path wins');
        self::assertNull($cfg['npx'], 'no npx next to that npm');
        self::assertSame(['node' => null, 'npm' => $other, 'npx' => null], Toolchain::configured(['npm_path' => $other]));
    }

    public function testShimsFollowTheOverride(): void
    {
        $dir = $this->tmp . '/node-bin';
        $node = $this->tool($this->tmp . '/a', 'node-22', 'echo v22.5.0');
        $npm = $this->tool($this->tmp . '/a', 'npm-22', 'echo 10.8.0');

        self::assertNull(Toolchain::shims($dir, ['node' => null, 'npm' => null, 'npx' => null]));
        self::assertDirectoryDoesNotExist($dir, 'nothing is created without an override');

        self::assertSame($dir, Toolchain::shims($dir, ['node' => $node, 'npm' => $npm, 'npx' => null]));
        self::assertSame($node, readlink($dir . '/node'));
        self::assertSame($npm, readlink($dir . '/npm'));
        self::assertFalse(is_link($dir . '/npx'));
        self::assertSame('22.5.0', Toolchain::version([$dir . '/node'], ['PATH' => '/usr/bin:/bin']), 'the link runs the configured binary');

        $newer = $this->tool($this->tmp . '/b', 'node', 'echo v24.0.0');
        Toolchain::shims($dir, ['node' => $newer, 'npm' => null, 'npx' => null]);
        self::assertSame($newer, readlink($dir . '/node'), 'a changed path replaces the link');
        self::assertFalse(is_link($dir . '/npm'), 'a cleared path removes its link');

        self::assertNull(Toolchain::shims($dir, ['node' => null, 'npm' => null, 'npx' => null]));
        self::assertFalse(is_link($dir . '/node'));
    }

    public function testCheckOverrideRunsTheConfiguredBinaries(): void
    {
        self::assertSame([[], null], Toolchain::checkOverride(['node_path' => '', 'npm_path' => '']));

        [$errors] = Toolchain::checkOverride(['node_path' => $this->tmp . '/missing/node', 'npm_path' => '']);
        self::assertSame('There is no file at this path.', $errors['node_path']);

        $private = $this->tool($this->tmp . '/private', 'node', 'echo v22.5.0', 0750);
        [$errors] = Toolchain::checkOverride(['node_path' => $private, 'npm_path' => '']);
        self::assertStringContainsString('not executable for others', $errors['node_path']);

        $old = $this->tool($this->tmp . '/old', 'node', 'echo v20.11.1');
        [$errors] = Toolchain::checkOverride(['node_path' => $old, 'npm_path' => '']);
        self::assertSame('This is Node.js 20.11.1; deploy scripts need 22 or newer.', $errors['node_path']);

        $bin = $this->tmp . '/opt/bin';
        $node = $this->tool($bin, 'node', 'echo v22.5.0');
        $this->tool($bin, 'npm', 'exit 1');
        [$errors] = Toolchain::checkOverride(['node_path' => $node, 'npm_path' => '']);
        self::assertStringContainsString('the npm next to this Node.js', $errors['node_path'], 'a broken npm next to node is reported on the node field');

        $this->tool($bin, 'npm', '[ "$(node --version)" = v22.5.0 ] && echo 10.8.0');
        [$errors, $summary] = Toolchain::checkOverride(['node_path' => $node, 'npm_path' => '']);
        self::assertSame([], $errors);
        self::assertSame("Deploy scripts now use Node.js 22.5.0 ({$node}) and npm 10.8.0 ({$bin}/npm).", $summary, 'npm ran on the configured node');
    }

    public function testWhichSearchesAbsoluteExecutableEntriesInOrder(): void
    {
        $this->tool($this->tmp . '/a', 'node', 'echo a', 0644); // not executable
        $b = $this->tool($this->tmp . '/b', 'node', 'echo b');
        $this->tool($this->tmp . '/c', 'node', 'echo c');
        $path = '::relative/dir:' . $this->tmp . '/a:' . $this->tmp . '/b/:' . $this->tmp . '/c';
        self::assertSame($b, Toolchain::which('node', $path));
        self::assertNull(Toolchain::which('npm', $path));
        self::assertNull(Toolchain::which('../b/node', $this->tmp . '/a'));
        self::assertNull(Toolchain::which('node', ''));
    }

    public function testVersionParsesTheVersionNumber(): void
    {
        $node = $this->tool($this->tmp . '/bin', 'node', '[ "$1" = --version ] && echo v22.11.0');
        $broken = $this->tool($this->tmp . '/bin', 'broken', 'echo "v1.2.3"; exit 1');
        $silent = $this->tool($this->tmp . '/bin', 'silent', 'echo hello');
        $env = ['PATH' => '/usr/bin:/bin'];
        self::assertSame('22.11.0', Toolchain::version([$node], $env));
        self::assertNull(Toolchain::version([$broken], $env), 'a failing command has no version');
        self::assertNull(Toolchain::version([$silent], $env));
        self::assertNull(Toolchain::version([$this->tmp . '/missing'], $env));
    }

    public function testNodeReportsBothToolsAndOnlyAsksNpmForItsVersionWhenWanted(): void
    {
        $bin = $this->tmp . '/bin';
        $this->tool($bin, 'node', 'echo v22.1.0');
        $this->tool($bin, 'npm', 'echo 10.7.0');
        $env = ['PATH' => $bin . ':/usr/bin:/bin'];

        $quick = Toolchain::node($bin, $env);
        self::assertSame(['path' => $bin . '/node', 'version' => '22.1.0', 'supported' => true], $quick['node']);
        self::assertSame(['path' => $bin . '/npm', 'version' => null], $quick['npm']);
        self::assertSame('10.7.0', Toolchain::node($bin, $env, true)['npm']['version']);

        $none = Toolchain::node($this->tmp . '/empty', $env);
        self::assertNull($none['node']);
        self::assertNull($none['npm']);
    }

    public function testNodeMustBeAtLeastTheMinimumMajorVersion(): void
    {
        self::assertSame(22, Toolchain::MIN_NODE_MAJOR);
        self::assertTrue(Toolchain::nodeSupported('22.0.0'));
        self::assertTrue(Toolchain::nodeSupported('24.1.0'));
        self::assertFalse(Toolchain::nodeSupported('21.7.3'));
        self::assertFalse(Toolchain::nodeSupported('20.11.1'));
        self::assertFalse(Toolchain::nodeSupported('8.17.0'));
        self::assertFalse(Toolchain::nodeSupported(null), 'a node that reports no version is not accepted');

        $bin = $this->tmp . '/old';
        $this->tool($bin, 'node', 'echo v20.11.1');
        $old = Toolchain::node($bin, ['PATH' => '/usr/bin:/bin']);
        self::assertSame(['path' => $bin . '/node', 'version' => '20.11.1', 'supported' => false], $old['node']);
    }

    /** A fake nvm installation in $home with the given Node.js versions. */
    private function nvm(string $home, string ...$versions): string
    {
        $dir = $home . '/.nvm';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        touch($dir . '/nvm.sh');
        foreach ($versions as $v) {
            $this->tool("{$dir}/versions/node/v{$v}/bin", 'node', "echo v{$v}");
            $this->tool("{$dir}/versions/node/v{$v}/bin", 'npm', 'echo 10.9.0');
        }
        return $dir;
    }

    private function alias(string $nvmDir, string $name, string $target): void
    {
        if (!is_dir(dirname($nvmDir . '/alias/' . $name))) {
            mkdir(dirname($nvmDir . '/alias/' . $name), 0755, true);
        }
        file_put_contents($nvmDir . '/alias/' . $name, $target . "\n");
    }

    public function testNvmVersionsAreFoundWhateverIsInstalled(): void
    {
        $home = $this->tmp . '/home';
        mkdir($home);
        self::assertNull(Toolchain::nvmBin($home), 'no nvm');
        $nvm = $this->nvm($home);
        self::assertNull(Toolchain::nvmBin($home), 'nvm without any Node.js version');

        $this->nvm($home, '20.11.1', '24.18.0', '22.11.0', '24.9.0');
        mkdir($nvm . '/versions/node/v25.0.0/bin', 0755, true); // unfinished install: no node binary
        self::assertSame(['24.18.0', '24.9.0', '22.11.0', '20.11.1'], Toolchain::nvmVersions($nvm), 'newest first, by version not by name');
        self::assertSame("{$nvm}/versions/node/v24.18.0/bin", Toolchain::nvmBin($home), 'without a default: the newest');

        $this->alias($nvm, 'default', '22');
        self::assertSame("{$nvm}/versions/node/v22.11.0/bin", Toolchain::nvmBin($home), "nvm's default alias wins");
        $this->alias($nvm, 'default', 'v24.9');
        self::assertSame("{$nvm}/versions/node/v24.9.0/bin", Toolchain::nvmBin($home));
        $this->alias($nvm, 'default', 'lts/*');
        $this->alias($nvm, 'lts/*', 'lts/jod');
        $this->alias($nvm, 'lts/jod', 'v22.11.0');
        self::assertSame("{$nvm}/versions/node/v22.11.0/bin", Toolchain::nvmBin($home), 'aliases are followed');
        $this->alias($nvm, 'default', '20');
        self::assertSame("{$nvm}/versions/node/v24.18.0/bin", Toolchain::nvmBin($home), 'a default older than 22 gives way to the newest that is new enough');
        $this->alias($nvm, 'default', '18');
        self::assertSame("{$nvm}/versions/node/v24.18.0/bin", Toolchain::nvmBin($home), 'a default that is not installed is ignored');

        self::assertSame(
            "/usr/bin:{$home}/.local/bin:{$home}/bin:{$nvm}/versions/node/v24.18.0/bin:/usr/local/bin:/usr/bin:/bin",
            Toolchain::deployPath('/usr/bin/php', $home),
            "nvm's Node.js comes after the user's bin directories and before the system's",
        );
    }

    public function testNvmNodeReportsTheVersionDeployScriptsUse(): void
    {
        $home = $this->tmp . '/home';
        mkdir($home);
        self::assertNull(Toolchain::nvmNode($home), 'no nvm');
        $nvm = $this->nvm($home, '20.11.1', '24.18.0');
        $this->alias($nvm, 'default', '20');
        self::assertSame(['version' => '24.18.0', 'npm' => true], Toolchain::nvmNode($home), 'the same pick as nvmBin()');
        unlink("{$nvm}/versions/node/v24.18.0/bin/npm");
        self::assertSame(['version' => '24.18.0', 'npm' => false], Toolchain::nvmNode($home), 'npm missing next to it');
    }

    public function testOnlyOldNvmVersionsAreStillUsedSoTheErrorNamesThem(): void
    {
        $home = $this->tmp . '/home';
        $nvm = $this->nvm($home, '18.20.4', '20.11.1');
        self::assertSame("{$nvm}/versions/node/v20.11.1/bin", Toolchain::nvmBin($home));
        $this->alias($nvm, 'default', '18');
        self::assertSame("{$nvm}/versions/node/v18.20.4/bin", Toolchain::nvmBin($home), "with nothing new enough, nvm's default stays");
    }

    public function testNvmInTheXdgConfigDirectory(): void
    {
        $home = $this->tmp . '/home';
        $this->tool("{$home}/.config/nvm/versions/node/v22.11.0/bin", 'node', 'echo v22.11.0');
        self::assertSame("{$home}/.config/nvm", Toolchain::nvmDir($home));
        self::assertSame("{$home}/.config/nvm/versions/node/v22.11.0/bin", Toolchain::nvmBin($home));
    }

    public function testHintPointsAtNodeInstalledForTheAccount(): void
    {
        $home = $this->tmp . '/home';
        mkdir($home);
        self::assertNull(Toolchain::hint($home));

        mkdir($home . '/.volta/bin', 0755, true);
        self::assertStringContainsString('.volta/bin', Toolchain::hint($home)['line']);

        $this->nvm($home);
        $hint = Toolchain::hint($home);
        self::assertSame(['text' => 'nvm is installed in ~/.nvm, but without any Node.js version', 'line' => 'nvm install 22', 'ssh' => true], $hint, 'nvm wins over Volta');
        self::assertSame('nvm is installed in ~/.nvm, but without any Node.js version. Run this once over SSH: nvm install 22', Toolchain::hintText($hint));

        $this->nvm($home, '20.11.1');
        self::assertSame('nvm is installed in ~/.nvm, but its newest Node.js is 20.11.1', Toolchain::hint($home)['text']);

        $this->nvm($home, '24.18.0');
        $hint = Toolchain::hint($home);
        self::assertFalse($hint['ssh']);
        self::assertSame('source ~/.nvm/nvm.sh && nvm use 24', $hint['line'], 'the script changed PATH: select the version in the script');
        self::assertStringContainsString('above $REQUIRE_NODE(): source ~/.nvm/nvm.sh && nvm use 24', Toolchain::hintText($hint));
        self::assertSame('nvm use 24', Toolchain::hint($home, ['NVM_DIR' => $home . '/.nvm'])['line'], 'nvm.sh was already sourced');
    }

    public function testSummaryNamesMissingToolsAndThePathSearched(): void
    {
        $php = PHP_BINARY;
        $env = ['PATH' => $this->tmp . '/empty:/nonexistent', 'HOME' => $this->tmp];
        $line = Toolchain::summary($php, null, $env, $this->tmp);
        self::assertMatchesRegularExpression('/^Toolchain: PHP \d+\.\d+\.\d+, Composer not found, Node\.js not found, npm not found \(PATH: /', $line);

        $bin = $this->tmp . '/bin';
        $this->tool($bin, 'node', 'echo v22.11.0');
        $this->tool($bin, 'npm', 'echo 10.9.0');
        $this->tool($bin, 'composer', 'echo "Composer version 2.8.4 2024-12-11 11:57:47"');
        $env['PATH'] = $bin . ':/usr/bin:/bin';
        $line = Toolchain::summary($php, null, $env, $this->tmp);
        self::assertStringEndsWith(', Composer 2.8.4, Node.js 22.11.0, npm 10.9.0', $line);
        self::assertStringNotContainsString('PATH:', $line);

        $this->tool($bin, 'node', 'echo v20.11.1');
        $line = Toolchain::summary($php, null, $env, $this->tmp);
        self::assertStringContainsString(", Node.js 20.11.1 ({$bin}/node: too old, 22 or newer is needed), npm 10.9.0", $line);
    }
}
