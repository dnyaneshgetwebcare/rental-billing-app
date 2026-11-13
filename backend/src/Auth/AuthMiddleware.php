<?php

declare(strict_types=1);

namespace App\Auth;

use App\Shared\ApiException;
use App\Shared\Context;
use App\Shared\Db;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private JwtService $jwt,
        private Context $context,
        private Db $db,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            throw ApiException::unauthorized();
        }
        $userId = $this->jwt->userIdFromToken($m[1]);
        $active = $this->db->scalar('SELECT is_active FROM users WHERE id = :id', [':id' => $userId]);
        if ($active === null || (int) $active !== 1) {
            throw ApiException::unauthorized('Account disabled.');
        }
        $this->context->setUser($userId);
        return $handler->handle($request->withAttribute('userId', $userId));
    }
}
