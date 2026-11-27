<?php

declare(strict_types=1);

namespace GitDeploy;

/**
 * An error the JSON API reports to the browser: HTTP status, a stable machine-readable
 * code the UI can react to (csrf, validation, busy, …), a human message, and optional
 * per-field messages for forms.
 */
final class ApiError extends \RuntimeException
{
    /** @param array<string,string> $fields */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $fields = [],
    ) {
        parent::__construct($message);
    }

    public static function validation(array $fields, string $message = 'Please correct the highlighted fields.'): self
    {
        return new self(422, 'validation', $message, $fields);
    }

    public static function notFound(string $message): self
    {
        return new self(404, 'not_found', $message);
    }

    public static function busy(string $message): self
    {
        return new self(409, 'busy', $message);
    }
}
