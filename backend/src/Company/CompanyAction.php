<?php

declare(strict_types=1);

namespace App\Company;

use App\Api\Shared\ResponseFactory;
use App\Shared\Context;
use App\Shared\Input;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class CompanyAction
{
    public function __construct(
        private CompanyService $companies,
        private Context $context,
        private ResponseFactory $response,
    ) {}

    /** Any logged-in user can create a company and becomes its admin. */
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $row = $this->companies->fields(Input::fromRequest($request), creating: true);
        $id = $this->companies->create($row, $this->context->userId());
        return $this->response->created($this->companies->get($id) + ['role' => Context::ROLE_ADMIN]);
    }

    public function current(): ResponseInterface
    {
        return $this->response->success($this->context->company() + ['role' => $this->context->role()]);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $this->context->requireRole(Context::ROLE_ADMIN);
        $row = $this->companies->fields(Input::fromRequest($request), creating: false);
        $this->companies->update($this->context->companyId(), $row);
        return $this->response->success($this->companies->get($this->context->companyId()));
    }
}
