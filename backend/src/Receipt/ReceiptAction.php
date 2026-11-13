<?php

declare(strict_types=1);

namespace App\Receipt;

use App\Api\Shared\ResponseFactory;
use App\Shared\CompanyScopedRepository;
use App\Shared\Context;
use App\Shared\Input;
use App\Shared\Page;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class ReceiptAction
{
    public function __construct(
        private ReceiptService $receipts,
        private CompanyScopedRepository $repo,
        private Context $context,
        private ResponseFactory $response,
    ) {}

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();
        $conditions = ['1 = 1'];
        $params = [];
        if (!empty($q['tenant_id'])) {
            $conditions[] = 't.tenant_id = :tenant';
            $params[':tenant'] = (int) $q['tenant_id'];
        }
        if (!empty($q['status'])) {
            $conditions[] = 't.status = :status';
            $params[':status'] = (string) $q['status'];
        }
        if (!empty($q['from'])) {
            $conditions[] = 't.receipt_date >= :from';
            $params[':from'] = (string) $q['from'];
        }
        if (!empty($q['to'])) {
            $conditions[] = 't.receipt_date <= :to';
            $params[':to'] = (string) $q['to'];
        }
        if (!empty($q['q'])) {
            $conditions[] = '(t.number LIKE :q OR tn.name LIKE :q OR t.reference LIKE :q)';
            $params[':q'] = '%' . addcslashes((string) $q['q'], '%_\\') . '%';
        }
        return $this->response->success($this->repo->paginate(
            'receipts t JOIN tenants tn ON tn.id = t.tenant_id JOIN accounts a ON a.id = t.account_id',
            implode(' AND ', $conditions),
            $params,
            't.receipt_date DESC, t.id DESC',
            Page::fromRequest($request),
            't.id, t.number, t.receipt_date, t.amount, t.unallocated_amount, t.mode, t.reference, t.status,
             t.tenant_id, tn.name AS tenant_name, a.name AS account_name',
        ));
    }

    public function view(CurrentRoute $route): ResponseInterface
    {
        return $this->response->success($this->receipts->detail((int) $route->getArgument('id')));
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $this->context->requireRole(Context::ROLE_ACCOUNTANT, Context::ROLE_STAFF);
        $id = $this->receipts->create(Input::fromRequest($request));
        return $this->response->created($this->receipts->detail($id));
    }

    public function cancel(CurrentRoute $route): ResponseInterface
    {
        $this->context->requireRole(Context::ROLE_ACCOUNTANT);
        $id = (int) $route->getArgument('id');
        $this->receipts->cancel($id);
        return $this->response->success($this->receipts->detail($id));
    }
}
