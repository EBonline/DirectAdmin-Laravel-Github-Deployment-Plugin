<?php

declare(strict_types=1);

namespace GitDeploy\Tests;

use GitDeploy\Env;
use GitDeploy\ScriptBuilder;

final class ScriptBuilderTest extends TestCase
{
    public function testDefaultTemplateIsValid(): void
    {
        $script = ScriptBuilder::defaultScript(Env::detect());
        self::assertSame([], ScriptBuilder::validate($script));
        self::assertStringContainsString('$DEPLOY_COMPOSER install', $script);
    }

    public function testDefaultTemplateRequiresNodeForTheFrontendBuild(): void
    {
        $script = ScriptBuilder::defaultScript(Env::detect());
        self::assertMatchesRegularExpression('/if \[ -f package\.json \]; then\n\s+\$REQUIRE_NODE\(\)\n\s+npm ci\n\s+npm run build\n/', $script);
        // `a && b` inside an if body is exempt from `set -e`: a failing `npm ci` would be ignored.
        self::assertStringNotContainsString('npm ci &&', $script);
        self::assertStringNotContainsString('command -v npm', $script, 'a missing npm must not skip the build silently');
        self::assertFalse(ScriptBuilder::isPreviousDefault($script));
    }

    public function testRecognisesThePreviousDefaultScript(): void
    {
        $previous = <<<'BASH'
$CREATE_RELEASE()

cd $DEPLOY_RELEASE_DIRECTORY

$DEPLOY_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

if command -v npm >/dev/null 2>&1 && [ -f package.json ]; then
    npm ci && npm run build
fi

$DEPLOY_PHP artisan storage:link
$DEPLOY_PHP artisan migrate --force
$DEPLOY_PHP artisan optimize

$ACTIVATE_RELEASE()

$RESTART_QUEUES()
BASH;
        self::assertTrue(ScriptBuilder::isPreviousDefault($previous));
        self::assertTrue(ScriptBuilder::isPreviousDefault(str_replace("\n", "\r\n", $previous) . "\n\n"), 'compared after normalising');
        self::assertFalse(ScriptBuilder::isPreviousDefault($previous . "\necho custom"));
    }

    public function testRequiresBothMacrosOnceAndInOrder(): void
    {
        self::assertNotEmpty(ScriptBuilder::validate("echo hi\n"));
        self::assertNotEmpty(ScriptBuilder::validate("\$CREATE_RELEASE()\n"));
        self::assertNotEmpty(ScriptBuilder::validate("\$ACTIVATE_RELEASE()\n\$CREATE_RELEASE()\n"));
        self::assertNotEmpty(ScriptBuilder::validate("\$CREATE_RELEASE()\n\$CREATE_RELEASE()\n\$ACTIVATE_RELEASE()\n"));
        self::assertSame([], ScriptBuilder::validate("\$CREATE_RELEASE()\necho build\n\$ACTIVATE_RELEASE()\n"));
    }

    public function testBuildReplacesMacrosAndNormalizesNewlines(): void
    {
        $out = ScriptBuilder::build("\$CREATE_RELEASE()\r\ncd \$DEPLOY_RELEASE_DIRECTORY\r\n\$REQUIRE_NODE()\r\n\$ACTIVATE_RELEASE()\r\n\$RESTART_QUEUES()");
        self::assertStringContainsString("__laravel_github_deploy require-node\n", $out);
        self::assertStringNotContainsString('$REQUIRE_NODE()', $out);
        self::assertStringStartsWith("#!/bin/bash\n", $out);
        self::assertStringContainsString('set -eo pipefail', $out);
        self::assertStringContainsString("__laravel_github_deploy create-release\n", $out);
        self::assertStringContainsString("__laravel_github_deploy activate-release\n", $out);
        self::assertStringContainsString("__laravel_github_deploy restart-queues\n", $out);
        self::assertStringContainsString('cd $DEPLOY_RELEASE_DIRECTORY', $out);
        self::assertStringNotContainsString("\r", $out);
        self::assertStringNotContainsString('$CREATE_RELEASE()', $out);
    }

    public function testBuiltScriptIsValidBash(): void
    {
        $file = $this->tmp . '/s.sh';
        file_put_contents($file, ScriptBuilder::build(ScriptBuilder::defaultScript(Env::detect())));
        exec('bash -n ' . escapeshellarg($file) . ' 2>&1', $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
    }
}
