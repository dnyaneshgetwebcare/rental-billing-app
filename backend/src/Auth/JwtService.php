<?php

declare(strict_types=1);

namespace App\Auth;

use App\Shared\ApiException;
use App\Shared\Settings;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

final class JwtService
{
    public const ACCESS_TTL = 900;

    public function issue(int $userId): string
    {
        $now = time();
        return JWT::encode([
            'iss' => Settings::appUrl(),
            'sub' => (string) $userId,
            'iat' => $now,
            'exp' => $now + self::ACCESS_TTL,
        ], Settings::jwtSecret(), 'HS256');
    }

    public function userIdFromToken(string $token): int
    {
        try {
            $payload = JWT::decode($token, new Key(Settings::jwtSecret(), 'HS256'));
        } catch (ExpiredException) {
            throw new ApiException('Session expired.', 'token_expired', 401);
        } catch (Throwable) {
            throw ApiException::unauthorized('Invalid token.');
        }
        $sub = $payload->sub ?? null;
        if (!is_string($sub) || !ctype_digit($sub)) {
            throw ApiException::unauthorized('Invalid token.');
        }
        return (int) $sub;
    }
}
