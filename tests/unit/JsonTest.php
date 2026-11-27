<?php

declare(strict_types=1);

namespace GitDeploy\Tests;

use GitDeploy\ApiError;
use GitDeploy\Json;

final class JsonTest extends TestCase
{
    private static function split(string $raw): array
    {
        [$head, $body] = explode("\n\n", $raw, 2);
        return [explode("\n", $head), json_decode($body, true)];
    }

    public function testOkResponseHasStatusLineHeadersAndServerTime(): void
    {
        [$head, $body] = self::split(Json::ok(['x' => 'ü/</script>']));
        self::assertSame('HTTP/1.1 200 OK', $head[0]);
        self::assertContains('Content-Type: application/json; charset=utf-8', $head);
        self::assertContains('Cache-Control: no-store', $head);
        self::assertTrue($body['ok']);
        self::assertSame('ü/</script>', $body['x']);
        self::assertIsInt($body['server_time']);
    }

    public function testErrorResponseCarriesCodeAndFields(): void
    {
        [$head, $body] = self::split(Json::error(ApiError::validation(['repo' => 'Nope.'])));
        self::assertSame('HTTP/1.1 422 Unprocessable Content', $head[0]);
        self::assertFalse($body['ok']);
        self::assertSame('validation', $body['error']['code']);
        self::assertSame(['repo' => 'Nope.'], $body['error']['fields']);

        [$head, $body] = self::split(Json::error(ApiError::busy('Wait.')));
        self::assertSame('HTTP/1.1 409 Conflict', $head[0]);
        self::assertArrayNotHasKey('fields', $body['error']);
    }

    public function testInvalidUtf8DoesNotBreakTheResponse(): void
    {
        [, $body] = self::split(Json::ok(['chunk' => "ok \xff\xfe"]));
        self::assertStringStartsWith('ok ', $body['chunk']);
    }
}
