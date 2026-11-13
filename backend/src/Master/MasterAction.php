<?php

declare(strict_types=1);

namespace App\Master;

use App\Api\Shared\ResponseFactory;
use App\Shared\ApiException;
use App\Shared\AuditLog;
use App\Shared\CompanyScopedRepository;
use App\Shared\Context;
use App\Shared\Input;
use App\Shared\Page;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use Yiisoft\Router\CurrentRoute;

/**
 * CRUD for /api/v1/{tenants|vendors|warehouses|units|tax-rates|accounts}.
 */
final readonly class MasterAction
{
    public function __construct(
        private MasterResources $resources,
        private CompanyScopedRepository $repo,
        private Context $context,
        private AuditLog $audit,
        private ResponseFactory $response,
    ) {}

    public function list(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $resource = $this->resource($route);
        $q = $this->resources->listQuery($resource, $request->getQueryParams());
        $page = Page::fromRequest($request, in_array($resource, ['accounts', 'tax-rates'], true) ? 200 : 25);
        return $this->response->success(
            $this->repo->paginate($q['from'], $q['condition'], $q['params'], $q['order'], $page, $q['select']),
        );
    }

    public function view(CurrentRoute $route): ResponseInterface
    {
        [$table, $label] = MasterResources::RESOURCES[$this->resource($route)];
        return $this->response->success($this->repo->get($table, $this->id($route), $label));
    }

    public function create(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $resource = $this->resource($route);
        [$table, $label, $roles] = MasterResources::RESOURCES[$resource];
        $this->context->requireRole(...$roles);

        $row = $this->resources->fields($resource, Input::fromRequest($request), creating: true);
        $id = $this->repo->db()->transaction(function () use ($table, $resource, $row): int {
            if ($resource === 'tax-rates' && $row['is_default'] === 1) {
                $this->clearDefaultTaxRate();
            }
            return $this->write(fn() => $this->repo->insert($table, $row));
        });
        $this->audit->record('create', $table, $id, $row);
        return $this->response->created($this->repo->get($table, $id, $label));
    }

    public function update(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $resource = $this->resource($route);
        [$table, $label, $roles] = MasterResources::RESOURCES[$resource];
        $this->context->requireRole(...$roles);
        $id = $this->id($route);
        $existing = $this->repo->get($table, $id, $label);

        $row = $this->resources->fields($resource, Input::fromRequest($request), creating: false, existing: $existing);
        $this->repo->db()->transaction(function () use ($table, $resource, $row, $id): void {
            if ($resource === 'tax-rates' && ($row['is_default'] ?? 0) === 1) {
                $this->clearDefaultTaxRate();
            }
            $this->write(fn() => $this->repo->update($table, $id, $row));
        });
        $this->audit->record('update', $table, $id, $row);
        return $this->response->success($this->repo->get($table, $id, $label));
    }

    public function delete(CurrentRoute $route): ResponseInterface
    {
        $resource = $this->resource($route);
        [$table, $label] = MasterResources::RESOURCES[$resource];
        $this->context->requireRole(Context::ROLE_ACCOUNTANT);
        $id = $this->id($route);
        $existing = $this->repo->get($table, $id, $label);
        if ($resource === 'accounts' && $existing['system_key'] !== null) {
            throw ApiException::conflict('System accounts are used by automatic posting and cannot be deleted.');
        }
        $this->repo->delete($table, $id, $label);
        $this->audit->record('delete', $table, $id, $existing);
        return $this->response->success(['deleted' => true]);
    }

    private function clearDefaultTaxRate(): void
    {
        $this->repo->db()->execute(
            'UPDATE tax_rates SET is_default = 0 WHERE company_id = :c',
            [':c' => $this->repo->companyId()],
        );
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function write(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            if (CompanyScopedRepository::isDuplicateError($e)) {
                throw ApiException::validation(['code' => 'This code is already used.']);
            }
            throw $e;
        }
    }

    private function resource(CurrentRoute $route): string
    {
        $resource = (string) $route->getArgument('resource');
        if (!isset(MasterResources::RESOURCES[$resource])) {
            throw ApiException::notFound('Resource');
        }
        return $resource;
    }

    private function id(CurrentRoute $route): int
    {
        return (int) $route->getArgument('id');
    }
}
