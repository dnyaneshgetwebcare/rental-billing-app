<?php

declare(strict_types=1);

namespace App\Company;

use App\Api\Shared\ResponseFactory;
use App\Auth\AuthService;
use App\Shared\ApiException;
use App\Shared\AuditLog;
use App\Shared\Context;
use App\Shared\Db;
use App\Shared\Input;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * Company users (admin only). Adding an email that already has an account just links it to this company.
 */
final readonly class UserAction
{
    public function __construct(
        private Db $db,
        private Context $context,
        private AuthService $auth,
        private AuditLog $audit,
        private ResponseFactory $response,
    ) {}

    public function list(): ResponseInterface
    {
        $this->context->requireRole(Context::ROLE_ADMIN);
        return $this->response->success($this->db->all(
            'SELECT u.id, u.name, u.email, u.phone, u.is_active, u.last_login_at, cu.role
             FROM company_users cu JOIN users u ON u.id = cu.user_id
             WHERE cu.company_id = :c ORDER BY u.name',
            [':c' => $this->context->companyId()],
        ));
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $this->context->requireRole(Context::ROLE_ADMIN);
        $in = Input::fromRequest($request);
        $email = $in->email('email', required: true);
        $role = $in->enum('role', Context::ROLES, required: true);
        $name = $in->string('name', max: 150);
        $password = $in->string('password', max: 200);
        $phone = $in->string('phone', max: 20);
        $in->validate();

        $companyId = $this->context->companyId();
        $userId = $this->db->transaction(function () use ($email, $role, $name, $password, $phone, $companyId): int {
            $existing = $this->db->scalar('SELECT id FROM users WHERE email = :e', [':e' => $email]);
            if ($existing === null) {
                if ($name === null || $name === '') {
                    throw ApiException::validation(['name' => 'Required for a new user.']);
                }
                $userId = $password === null
                    ? $this->auth->createInvitedUser($name, (string) $email, $phone)
                    : $this->auth->createUser($name, (string) $email, $password, $phone);
            } else {
                $userId = (int) $existing;
                if ($this->db->scalar('SELECT id FROM company_users WHERE company_id = :c AND user_id = :u', [':c' => $companyId, ':u' => $userId]) !== null) {
                    throw ApiException::validation(['email' => 'This user already has access to the company.']);
                }
            }
            $this->db->insert('company_users', [
                'company_id' => $companyId,
                'user_id' => $userId,
                'role' => $role,
                'created_at' => Db::now(),
            ]);
            return $userId;
        });
        $this->audit->record('add_user', 'user', $userId, ['email' => $email, 'role' => $role]);
        return $this->response->created(['id' => $userId, 'email' => $email, 'role' => $role]);
    }

    public function update(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $this->context->requireRole(Context::ROLE_ADMIN);
        $userId = (int) $route->getArgument('id');
        $in = Input::fromRequest($request);
        $role = $in->enum('role', Context::ROLES, required: true);
        $in->validate();
        $this->guardLastAdmin($userId, $role);
        $updated = $this->db->update('company_users', ['role' => $role], ['company_id' => $this->context->companyId(), 'user_id' => $userId]);
        if ($updated === 0 && $this->membership($userId) === null) {
            throw ApiException::notFound('User');
        }
        $this->audit->record('change_role', 'user', $userId, ['role' => $role]);
        return $this->response->success(['id' => $userId, 'role' => $role]);
    }

    public function delete(CurrentRoute $route): ResponseInterface
    {
        $this->context->requireRole(Context::ROLE_ADMIN);
        $userId = (int) $route->getArgument('id');
        if ($this->membership($userId) === null) {
            throw ApiException::notFound('User');
        }
        $this->guardLastAdmin($userId, null);
        $this->db->delete('company_users', ['company_id' => $this->context->companyId(), 'user_id' => $userId]);
        $this->audit->record('remove_user', 'user', $userId);
        return $this->response->success(['removed' => true]);
    }

    private function membership(int $userId): ?string
    {
        $role = $this->db->scalar(
            'SELECT role FROM company_users WHERE company_id = :c AND user_id = :u',
            [':c' => $this->context->companyId(), ':u' => $userId],
        );
        return $role === null ? null : (string) $role;
    }

    private function guardLastAdmin(int $userId, ?string $newRole): void
    {
        if ($this->membership($userId) !== Context::ROLE_ADMIN || $newRole === Context::ROLE_ADMIN) {
            return;
        }
        $admins = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM company_users WHERE company_id = :c AND role = 'admin'",
            [':c' => $this->context->companyId()],
        );
        if ($admins <= 1) {
            throw ApiException::conflict('A company needs at least one admin.');
        }
    }
}
