<?php

declare(strict_types=1);

namespace App\Shared;

use RuntimeException;
use Yiisoft\Http\Status;

/**
 * An error that is safe to show to the API client.
 */
final class ApiException extends RuntimeException
{
    /**
     * @param array<string, list<string>> $fields
     */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'bad_request',
        public readonly int $httpStatus = Status::BAD_REQUEST,
        public readonly array $fields = [],
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $what = 'Record'): self
    {
        return new self("$what not found.", 'not_found', Status::NOT_FOUND);
    }

    public static function forbidden(string $message = 'You do not have permission for this action.'): self
    {
        return new self($message, 'forbidden', Status::FORBIDDEN);
    }

    public static function unauthorized(string $message = 'Login required.'): self
    {
        return new self($message, 'unauthorized', Status::UNAUTHORIZED);
    }

    /**
     * @param array<string, list<string>|string> $fields
     */
    public static function validation(array $fields, string $message = 'Validation failed.'): self
    {
        $normalized = [];
        foreach ($fields as $field => $errors) {
            $normalized[$field] = is_array($errors) ? array_values($errors) : [$errors];
        }
        return new self($message, 'validation_failed', Status::UNPROCESSABLE_ENTITY, $normalized);
    }

    public static function conflict(string $message): self
    {
        return new self($message, 'conflict', Status::CONFLICT);
    }
}
