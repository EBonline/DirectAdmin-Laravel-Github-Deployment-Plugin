<?php

declare(strict_types=1);

namespace GitDeploy;

/** Responses of the *.raw API scripts, which must print their own HTTP status line and headers. */
final class Json
{
    private const REASONS = [
        200 => 'OK',
        400 => 'Bad Request',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        409 => 'Conflict',
        422 => 'Unprocessable Content',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
    ];

    public static function response(array $data, int $status = 200): string
    {
        $reason = self::REASONS[$status] ?? 'OK';
        $body = json_encode(
            $data + ['server_time' => time()],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        return "HTTP/1.1 {$status} {$reason}\n"
            . "Content-Type: application/json; charset=utf-8\n"
            . "Cache-Control: no-store\n"
            . "X-Content-Type-Options: nosniff\n\n"
            . ($body === false ? '{"ok":false,"error":{"code":"server","message":"Could not encode the response."}}' : $body);
    }

    public static function ok(array $data = []): string
    {
        return self::response(['ok' => true] + $data);
    }

    public static function error(ApiError $e): string
    {
        $error = ['code' => $e->errorCode, 'message' => $e->getMessage()];
        if ($e->fields !== []) {
            $error['fields'] = $e->fields;
        }
        return self::response(['ok' => false, 'error' => $error], $e->status);
    }
}
