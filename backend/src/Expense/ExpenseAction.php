<?php

declare(strict_types=1);

namespace App\Expense;

use App\Api\Shared\ResponseFactory;
use App\Shared\CompanyScopedRepository;
use App\Shared\Context;
use App\Shared\Input;
use App\Shared\Page;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class ExpenseAction
{
    public function __construct(
        private ExpenseService $expenses,
        private CompanyScopedRepository $repo,
        private Context $context,
        private ResponseFactory $response,
    ) {}

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();
        $conditions = ['1 = 1'];
        $params = [];
        if (!empty($q['account_id'])) {
            $conditions[] = 't.account_id = :acc';
            $params[':acc'] = (int) $q['account_id'];
        }
        if (!empty($q['from'])) {
            $conditions[] = 't.expense_date >= :from';
            $params[':from'] = (string) $q['from'];
        }
        if (!empty($q['to'])) {
            $conditions[] = 't.expense_date <= :to';
            $params[':to'] = (string) $q['to'];
        }
        if (!empty($q['q'])) {
            $conditions[] = '(t.description LIKE :q OR t.reference LIKE :q)';
            $params[':q'] = '%' . addcslashes((string) $q['q'], '%_\\') . '%';
        }
        return $this->response->success($this->repo->paginate(
            'expenses t JOIN accounts a ON a.id = t.account_id JOIN accounts f ON f.id = t.paid_from_account_id',
            implode(' AND ', $conditions),
            $params,
            't.expense_date DESC, t.id DESC',
            Page::fromRequest($request),
            't.id, t.expense_date, t.description, t.amount, t.cgst, t.sgst, t.igst, t.total, t.payment_mode, t.reference,
             t.status, a.name AS account_name, f.name AS paid_from_name,
             (SELECT COUNT(*) FROM attachments at WHERE at.owner_type = \'expense\' AND at.owner_id = t.id) AS attachment_count',
        ));
    }

    public function view(CurrentRoute $route): ResponseInterface
    {
        return $this->response->success($this->expenses->detail((int) $route->getArgument('id')));
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $this->context->requireRole(Context::ROLE_ACCOUNTANT, Context::ROLE_STAFF);
        $id = $this->expenses->create(Input::fromRequest($request));
        return $this->response->created($this->expenses->detail($id));
    }

    public function cancel(CurrentRoute $route): ResponseInterface
    {
        $this->context->requireRole(Context::ROLE_ACCOUNTANT);
        $id = (int) $route->getArgument('id');
        $this->expenses->cancel($id);
        return $this->response->success($this->expenses->detail($id));
    }
}
