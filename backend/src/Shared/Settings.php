<?php

declare(strict_types=1);

namespace App\Shared;

use RuntimeException;

/**
 * Values from backend/.env (or real environment variables).
 */
final class Settings
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key, true);
        if ($value === false) {
            $value = getenv($key);
        }
        if ($value === false) {
            $value = isset($_ENV[$key]) ? (string) $_ENV[$key] : null;
        }
        return $value === null || $value === '' ? $default : $value;
    }

    public static function require(string $key): string
    {
        $value = self::get($key);
        if ($value === null) {
            throw new RuntimeException("Missing required setting $key in backend/.env");
        }
        return $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function jwtSecret(): string
    {
        $secret = self::require('JWT_SECRET');
        if (strlen($secret) < 32) {
            throw new RuntimeException('JWT_SECRET must be at least 32 characters.');
        }
        return $secret;
    }

    public static function appUrl(): string
    {
        return rtrim(self::get('APP_URL', 'http://localhost:8082') ?? '', '/');
    }

    public static function uploadDir(): string
    {
        return rtrim(self::get('UPLOAD_DIR', dirname(__DIR__, 3) . '/public_html/uploads') ?? '', '/');
    }

    public static function uploadUrl(): string
    {
        return rtrim(self::get('UPLOAD_URL', '/uploads') ?? '', '/');
    }
}
