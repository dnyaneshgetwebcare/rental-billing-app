<?php

declare(strict_types=1);

namespace App\Auth;

use App\Shared\ApiException;
use App\Shared\Db;

final readonly class AuthService
{
    public const REFRESH_TTL_DAYS = 30;
    private const MAX_FAILED_LOGINS = 10;

    public function __construct(
        private Db $db,
        private JwtService $jwt,
    ) {}

    /**
     * @return array{user: array<string, mixed>, access_token: string, refresh_token: string}
     */
    public function login(string $email, string $password, ?string $userAgent): array
    {
        $email = strtolower(trim($email));
        $failed = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'login_failed' AND entity = 'user'
             AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.email')) = :email AND created_at > :since",
            [':email' => $email, ':since' => date('Y-m-d H:i:s', time() - 900)],
        );
        if ($failed >= self::MAX_FAILED_LOGINS) {
            throw new ApiException('Too many failed attempts. Try again in 15 minutes.', 'too_many_attempts', 429);
        }

        $user = $this->db->one('SELECT * FROM users WHERE email = :email', [':email' => $email]);
        $hash = $user['password_hash'] ?? null;
        if ($user === null || !$user['is_active'] || !is_string($hash) || $hash === '' || !password_verify($password, $hash)) {
            $this->db->insert('audit_logs', [
                'action' => 'login_failed',
                'entity' => 'user',
                'entity_id' => $user['id'] ?? null,
                'data' => json_encode(['email' => $email]),
                'ip' => isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null,
                'created_at' => Db::now(),
            ]);
            throw ApiException::unauthorized('Wrong email or password.');
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $this->db->update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], ['id' => $user['id']]);
        }
        $this->db->update('users', ['last_login_at' => Db::now()], ['id' => $user['id']]);

        return $this->sessionFor($user, $userAgent);
    }

    /**
     * Signs in a Google account. An email an admin already added is linked on the first visit.
     * An unknown email becomes a new user with no company yet (they create one next).
     *
     * @return array{user: array<string, mixed>, access_token: string, refresh_token: string}
     */
    public function loginWithGoogle(string $idToken, ?string $userAgent): array
    {
        $profile = (new GoogleIdentity())->verify($idToken);

        return $this->db->transaction(function () use ($profile, $userAgent): array {
            $user = $this->db->one(
                'SELECT * FROM users WHERE google_sub = :sub OR email = :email LIMIT 1 FOR UPDATE',
                [':sub' => $profile['sub'], ':email' => $profile['email']],
            );
            if ($user !== null && !(int) $user['is_active']) {
                throw ApiException::unauthorized('This account is disabled.');
            }
            if ($user !== null && $user['google_sub'] !== null && (string) $user['google_sub'] !== $profile['sub']) {
                throw ApiException::unauthorized('This email is already linked to another Google account.');
            }

            $now = Db::now();
            if ($user === null) {
                $id = $this->db->insert('users', [
                    'name' => mb_substr($profile['name'], 0, 150),
                    'email' => $profile['email'],
                    'google_sub' => $profile['sub'],
                    'password_hash' => null,
                    'is_active' => 1,
                    'last_login_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $user = $this->db->one('SELECT * FROM users WHERE id = :id', [':id' => $id])
                    ?? throw ApiException::unauthorized('Google sign-in failed.');
            } else {
                $this->db->update('users', [
                    'google_sub' => $profile['sub'],
                    'last_login_at' => $now,
                    'updated_at' => $now,
                ], ['id' => $user['id']]);
            }

            return $this->sessionFor($user, $userAgent);
        });
    }

    /**
     * Rotates the refresh token: the old one is revoked and a new one issued.
     *
     * @return array{access_token: string, refresh_token: string}
     */
    public function refresh(string $refreshToken, ?string $userAgent): array
    {
        return $this->db->transaction(function () use ($refreshToken, $userAgent): array {
            $row = $this->db->one(
                'SELECT rt.*, u.is_active FROM refresh_tokens rt JOIN users u ON u.id = rt.user_id
                 WHERE rt.token_hash = :hash FOR UPDATE',
                [':hash' => hash('sha256', $refreshToken)],
            );
            if ($row === null || $row['revoked_at'] !== null || $row['expires_at'] < Db::now() || !$row['is_active']) {
                if ($row !== null && $row['revoked_at'] !== null) {
                    // A revoked token was reused: assume theft and log the user out everywhere.
                    $this->revokeAll((int) $row['user_id']);
                }
                throw ApiException::unauthorized('Session expired. Please log in again.');
            }
            $this->db->update('refresh_tokens', ['revoked_at' => Db::now()], ['id' => $row['id']]);
            $userId = (int) $row['user_id'];
            return [
                'access_token' => $this->jwt->issue($userId),
                'refresh_token' => $this->createRefreshToken($userId, $userAgent),
            ];
        });
    }

    public function logout(?string $refreshToken): void
    {
        if ($refreshToken === null || $refreshToken === '') {
            return;
        }
        $this->db->execute(
            'UPDATE refresh_tokens SET revoked_at = :now WHERE token_hash = :hash AND revoked_at IS NULL',
            [':now' => Db::now(), ':hash' => hash('sha256', $refreshToken)],
        );
    }

    public function revokeAll(int $userId): void
    {
        $this->db->execute(
            'UPDATE refresh_tokens SET revoked_at = :now WHERE user_id = :user AND revoked_at IS NULL',
            [':now' => Db::now(), ':user' => $userId],
        );
    }

    public function createUser(string $name, string $email, string $password, ?string $phone = null): int
    {
        if (strlen($password) < 8) {
            throw ApiException::validation(['password' => 'Use at least 8 characters.']);
        }
        $email = strtolower(trim($email));
        if ($this->db->scalar('SELECT id FROM users WHERE email = :e', [':e' => $email]) !== null) {
            throw ApiException::validation(['email' => 'A user with this email already exists.']);
        }
        return $this->insertUser($name, $email, password_hash($password, PASSWORD_DEFAULT), $phone, null);
    }

    /** A person who will sign in with Google. An admin adds their Gmail; there is no password. */
    public function createInvitedUser(string $name, string $email, ?string $phone = null): int
    {
        return $this->insertUser($name, $email, null, $phone, null);
    }

    private function insertUser(string $name, string $email, ?string $passwordHash, ?string $phone, ?string $googleSub): int
    {
        $email = strtolower(trim($email));
        if ($this->db->scalar('SELECT id FROM users WHERE email = :e', [':e' => $email]) !== null) {
            throw ApiException::validation(['email' => 'A user with this email already exists.']);
        }
        $now = Db::now();
        return $this->db->insert('users', [
            'name' => $name,
            'email' => $email,
            'google_sub' => $googleSub,
            'phone' => $phone,
            'password_hash' => $passwordHash,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function changePassword(int $userId, string $current, string $new): void
    {
        $user = $this->db->one('SELECT * FROM users WHERE id = :id', [':id' => $userId]) ?? throw ApiException::notFound('User');
        $hash = $user['password_hash'] ?? null;
        if (!is_string($hash) || $hash === '' || !password_verify($current, $hash)) {
            throw ApiException::validation(['current_password' => 'Current password is wrong.']);
        }
        if (strlen($new) < 8) {
            throw ApiException::validation(['new_password' => 'Use at least 8 characters.']);
        }
        $this->db->update('users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT), 'updated_at' => Db::now()], ['id' => $userId]);
        $this->revokeAll($userId);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'phone' => $user['phone'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array{user: array<string, mixed>, access_token: string, refresh_token: string}
     */
    private function sessionFor(array $user, ?string $userAgent): array
    {
        return [
            'user' => self::publicUser($user),
            'access_token' => $this->jwt->issue((int) $user['id']),
            'refresh_token' => $this->createRefreshToken((int) $user['id'], $userAgent),
        ];
    }

    private function createRefreshToken(int $userId, ?string $userAgent): string
    {
        $token = bin2hex(random_bytes(32));
        $this->db->insert('refresh_tokens', [
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', time() + self::REFRESH_TTL_DAYS * 86400),
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
            'created_at' => Db::now(),
        ]);
        return $token;
    }
}
