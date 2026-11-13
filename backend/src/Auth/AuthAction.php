<?php

declare(strict_types=1);

namespace App\Auth;

use App\Api\Shared\ResponseFactory;
use App\Shared\Context;
use App\Shared\Db;
use App\Shared\Input;
use App\Shared\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AuthAction
{
    private const COOKIE = 'rb_refresh';
    private const COOKIE_PATH = '/api/v1/auth';

    public function __construct(
        private AuthService $auth,
        private ResponseFactory $response,
        private Context $context,
        private Db $db,
    ) {}

    public function login(ServerRequestInterface $request): ResponseInterface
    {
        $in = Input::fromRequest($request);
        $email = $in->email('email', required: true);
        $password = $in->string('password', required: true, max: 200);
        $in->validate();

        $result = $this->auth->login((string) $email, (string) $password, $request->getHeaderLine('User-Agent') ?: null);
        $userId = (int) $result['user']['id'];

        return $this->withRefreshCookie(
            $this->response->success([
                'access_token' => $result['access_token'],
                'expires_in' => JwtService::ACCESS_TTL,
                'user' => $result['user'],
                'companies' => $this->companiesFor($userId),
            ]),
            $result['refresh_token'],
        );
    }

    public function google(ServerRequestInterface $request): ResponseInterface
    {
        $in = Input::fromRequest($request);
        $idToken = $in->string('id_token', required: true, max: 4000);
        $in->validate();

        $result = $this->auth->loginWithGoogle((string) $idToken, $request->getHeaderLine('User-Agent') ?: null);
        $userId = (int) $result['user']['id'];

        return $this->withRefreshCookie(
            $this->response->success([
                'access_token' => $result['access_token'],
                'expires_in' => JwtService::ACCESS_TTL,
                'user' => $result['user'],
                'companies' => $this->companiesFor($userId),
            ]),
            $result['refresh_token'],
        );
    }

    public function refresh(ServerRequestInterface $request): ResponseInterface
    {
        $token = $request->getCookieParams()[self::COOKIE] ?? '';
        $result = $this->auth->refresh((string) $token, $request->getHeaderLine('User-Agent') ?: null);
        return $this->withRefreshCookie(
            $this->response->success(['access_token' => $result['access_token'], 'expires_in' => JwtService::ACCESS_TTL]),
            $result['refresh_token'],
        );
    }

    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        $this->auth->logout($request->getCookieParams()[self::COOKIE] ?? null);
        return $this->withRefreshCookie($this->response->success(['logged_out' => true]), null);
    }

    public function me(): ResponseInterface
    {
        $userId = $this->context->userId();
        $user = $this->db->one('SELECT * FROM users WHERE id = :id', [':id' => $userId]);
        return $this->response->success([
            'user' => AuthService::publicUser((array) $user),
            'companies' => $this->companiesFor($userId),
        ]);
    }

    public function changePassword(ServerRequestInterface $request): ResponseInterface
    {
        $in = Input::fromRequest($request);
        $current = $in->string('current_password', required: true, max: 200);
        $new = $in->string('new_password', required: true, max: 200);
        $in->validate();
        $this->auth->changePassword($this->context->userId(), (string) $current, (string) $new);
        return $this->withRefreshCookie($this->response->success(['changed' => true]), null);
    }

    /** @return list<array<string, mixed>> */
    private function companiesFor(int $userId): array
    {
        return $this->db->all(
            'SELECT c.id, c.name, c.gstin, c.state_code, c.invoice_prefix, cu.role
             FROM company_users cu JOIN companies c ON c.id = cu.company_id
             WHERE cu.user_id = :user ORDER BY c.name',
            [':user' => $userId],
        );
    }

    private function withRefreshCookie(ResponseInterface $response, ?string $token): ResponseInterface
    {
        $parts = [
            self::COOKIE . '=' . ($token ?? ''),
            'Path=' . self::COOKIE_PATH,
            'HttpOnly',
            'SameSite=Strict',
            $token === null
                ? 'Max-Age=0'
                : 'Max-Age=' . (AuthService::REFRESH_TTL_DAYS * 86400),
        ];
        if (Settings::bool('COOKIE_SECURE', true)) {
            $parts[] = 'Secure';
        }
        return $response->withAddedHeader('Set-Cookie', implode('; ', $parts));
    }
}
