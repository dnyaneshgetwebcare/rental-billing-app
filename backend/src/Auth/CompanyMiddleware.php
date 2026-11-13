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

/**
 * Requires X-Company-Id and checks that the logged-in user belongs to that company.
 */
final readonly class CompanyMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Context $context,
        private Db $db,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $raw = $request->getHeaderLine('X-Company-Id');
        if ($raw === '' || !ctype_digit($raw)) {
            throw new ApiException('Select a company first (X-Company-Id header).', 'company_required');
        }
        $row = $this->db->one(
            'SELECT c.*, cu.role FROM companies c JOIN company_users cu ON cu.company_id = c.id
             WHERE c.id = :company AND cu.user_id = :user',
            [':company' => (int) $raw, ':user' => $this->context->userId()],
        );
        if ($row === null) {
            throw ApiException::forbidden('You do not have access to this company.');
        }
        $role = (string) $row['role'];
        unset($row['role']);
        $this->context->setCompany($row, $role);
        return $handler->handle($request->withAttribute('companyId', (int) $row['id']));
    }
}
