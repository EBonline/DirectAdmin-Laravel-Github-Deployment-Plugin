<?php

declare(strict_types=1);

namespace GitDeploy\Tests;

use GitDeploy\Csrf;
use GitDeploy\Request;

final class RequestCsrfTest extends TestCase
{
    public function testParseDecodesEntityEncodedSeparators(): void
    {
        self::assertSame(['a' => '1', 'b' => 'x y'], Request::parse('a=1&amp;b=x+y'));
        self::assertSame(['a' => '1', 'b' => '&<>'], Request::parse('a=1&b=%26%3C%3E'));
    }

    public function testPayloadIsBase64UrlJson(): void
    {
        $data = ['script' => "echo \"a & b < c\" 'q' %2B \$HOME\n", 'name' => 'ü ✓', 'n' => 3, 'on' => true];
        $b64 = rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');
        self::assertSame($data, Request::fake([], ['payload' => $b64])->payload());
        // DirectAdmin may HTML-encode the separators of the POST string; the payload survives.
        self::assertSame($data, Request::fake([], Request::parse('csrf=x&amp;payload=' . $b64))->payload());
        self::assertSame([], Request::fake([], ['csrf' => 'x'])->payload());
        self::assertNull(Request::fake([], ['payload' => '!!not base64!!'])->payload());
        self::assertNull(Request::fake([], ['payload' => rtrim(base64_encode('"just a string"'), '=')])->payload());
    }

    public function testWithPostTurnsPayloadIntoFormValues(): void
    {
        $req = Request::fake(['a' => 'q'])->withPost(['flag' => true, 'off' => false, 'n' => 4, 's' => 'x', 'list' => ['.env', 'storage'], 'null' => null]);
        self::assertTrue($req->isPost);
        self::assertTrue($req->checkbox('flag'));
        self::assertFalse($req->checkbox('off'));
        self::assertSame('4', $req->input('n'));
        self::assertSame("x", $req->input('s'));
        self::assertSame(".env\nstorage", $req->text('list'));
        self::assertSame('', $req->input('null'));
        self::assertSame('q', $req->query('a'));
    }

    public function testCsrf(): void
    {
        $csrf = new Csrf($this->tmp . '/secret', 'user|alice');
        $token = $csrf->token();
        self::assertTrue($csrf->verify($token));
        self::assertFalse($csrf->verify($token . 'x'));
        self::assertFalse((new Csrf($this->tmp . '/secret', 'user|bob'))->verify($token));
        self::assertFalse($csrf->verify((time() - 9 * 3600) . '.abc'));
        self::assertFalse($csrf->verify(''));
        self::assertSame('600', substr(sprintf('%o', fileperms($this->tmp . '/secret')), -3));
    }
}
