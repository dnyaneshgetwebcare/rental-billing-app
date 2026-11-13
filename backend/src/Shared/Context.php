<?php

declare(strict_types=1);

namespace App\Shared;

/**
 * The logged-in user and the active company for the current request (or console job).
 * Filled by AuthMiddleware and CompanyMiddleware; PHP handles one request per process,
 * so a shared service instance is safe.
 */
final class Context
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_ACCOUNTANT = 'accountant';
    public const ROLE_STAFF = 'staff';
    public const ROLES = [self::ROLE_ADMIN, self::ROLE_ACCOUNTANT, self::ROLE_STAFF];

    private ?int $userId = null;
    private ?int $companyId = null;
    private ?string $role = null;
    /** @var array<string, mixed>|null */
    private ?array $company = null;

    public function setUser(int $userId): void
    {
        $this->userId = $userId;
    }

    /**
     * @param array<string, mixed> $company
     */
    public function setCompany(array $company, string $role): void
    {
        $this->companyId = (int) $company['id'];
        $this->company = $company;
        $this->role = $role;
    }

    public function clearCompany(): void
    {
        $this->companyId = null;
        $this->company = null;
        $this->role = null;
    }

    public function userId(): int
    {
        return $this->userId ?? throw ApiException::unauthorized();
    }

    public function userIdOrNull(): ?int
    {
        return $this->userId;
    }

    public function companyId(): int
    {
        return $this->companyId ?? throw new ApiException('Select a company first (X-Company-Id header).', 'company_required');
    }

    /** @return array<string, mixed> */
    public function company(): array
    {
        return $this->company ?? throw new ApiException('Select a company first (X-Company-Id header).', 'company_required');
    }

    public function role(): ?string
    {
        return $this->role;
    }

    /**
     * Admin can do everything; accountant can do everything except user/company admin; staff can create bills,
     * receipts and masters but not accounting entries or settings.
     */
    public function requireRole(string ...$roles): void
    {
        if ($this->role === self::ROLE_ADMIN) {
            return;
        }
        if (!in_array($this->role, $roles, true)) {
            throw ApiException::forbidden();
        }
    }

    public function can(string ...$roles): bool
    {
        return $this->role === self::ROLE_ADMIN || in_array($this->role, $roles, true);
    }
}
