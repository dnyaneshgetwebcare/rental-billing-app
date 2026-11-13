<?php

declare(strict_types=1);

namespace App\Purchase;

use App\Api\Shared\ResponseFactory;
use App\Shared\CompanyScopedRepository;
use App\Shared\Context;
use App\Shared\Input;
use App\Shared\Page;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class PurchaseAction
{
    public function __construct(
        private PurchaseService $purchases,
        private CompanyScopedRepository $repo,
        private Context $context,
        private ResponseFactory $response,
    ) {}

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();
        $conditions = ['1 = 1'];
        $params = [];
        if (!empty($q['vendor_id'])) {
            $conditions[] = 't.vendor_id = :vendor';
            $params[':vendor'] = (int) $q['vendor_id'];
        }
        if (!empty($q['status'])) {
            if ($q['status'] === 'outstanding') {
                $conditions[] = "t.status IN ('unpaid','partial')";
            } else {
                $conditions[] = 't.status = :status';
                $params[':status'] = (string) $q['status'];
            }
        }
        if (!empty($q['from'])) {
            $conditions[] = 't.bill_date >= :from';
            $params[':from'] = (string) $q['from'];
        }
        if (!empty($q['to'])) {
            $conditions[] = 't.bill_date <= :to';
            $params[':to'] = (string) $q['to'];
        }
        if (!empty($q['q'])) {
            $conditions[] = '(t.bill_no LIKE :q OR v.name LIKE :q)';
            $params[':q'] = '%' . addcslashes((string) $q['q'], '%_\\') . '%';
        }
        return $this->response->success($this->repo->paginate(
            'purchases t JOIN vendors v ON v.id = t.vendor_id',
            implode(' AND ', $conditions),
            $params,
            't.bill_date DESC, t.id DESC',
            Page::fromRequest($request),
            't.id, t.bill_no, t.bill_date, t.taxable_amount, t.cgst, t.sgst, t.igst, t.total, t.paid_amount,
             (t.total - t.paid_amount) AS balance, t.status, t.vendor_id, v.name AS vendor_name',
        ));
    }

    public function view(CurrentRoute $route): ResponseInterface
    {
        return $this->response->success($this->purchases->detail((int) $route->getArgument('id')));
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $this->context->requireRole(Context::ROLE_ACCOUNTANT);
        $id = $this->purchases->create(Input::fromRequest($request));
        return $this->response->created($this->purchases->detail($id));
    }

    public function cancel(CurrentRoute $route): ResponseInterface
    {
        $this->context->requireRole(Context::ROLE_ACCOUNTANT);
        $id = (int) $route->getArgument('id');
        $this->purchases->cancel($id);
        return $this->response->success($this->purchases->detail($id));
    }

    public function paymentList(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();
        $conditions = ['1 = 1'];
        $params = [];
        if (!empty($q['vendor_id'])) {
            $conditions[] = 't.vendor_id = :vendor';
            $params[':vendor'] = (int) $q['vendor_id'];
        }
        return $this->response->success($this->repo->paginate(
            'vendor_payments t JOIN vendors v ON v.id = t.vendor_id JOIN accounts a ON a.id = t.account_id LEFT JOIN purchases p ON p.id = t.purchase_id',
            implode(' AND ', $conditions),
            $params,
            't.payment_date DESC, t.id DESC',
            Page::fromRequest($request),
            't.*, v.name AS vendor_name, a.name AS account_name, p.bill_no',
        ));
    }

    public function paymentCreate(ServerRequestInterface $request): ResponseInterface
    {
        $this->context->requireRole(Context::ROLE_ACCOUNTANT);
        $id = $this->purchases->createPayment(Input::fromRequest($request));
        return $this->response->created($this->repo->get('vendor_payments', $id, 'Payment'));
    }

    public function paymentCancel(CurrentRoute $route): ResponseInterface
    {
        $this->context->requireRole(Context::ROLE_ACCOUNTANT);
        $id = (int) $route->getArgument('id');
        $this->purchases->cancelPayment($id);
        return $this->response->success($this->repo->get('vendor_payments', $id, 'Payment'));
    }
}
