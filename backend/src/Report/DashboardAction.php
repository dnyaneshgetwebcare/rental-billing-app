<?php

declare(strict_types=1);

namespace App\Report;

use App\Agreement\AutoBillingService;
use App\Api\Shared\ResponseFactory;
use App\Shared\Context;
use App\Shared\Db;
use App\Shared\Money;
use App\Shared\Page;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class DashboardAction
{
    public function __construct(
        private Db $db,
        private Context $context,
        private ReportService $reports,
        private AutoBillingService $billing,
        private ResponseFactory $response,
    ) {}

    public function summary(): ResponseInterface
    {
        $c = $this->context->companyId();
        $today = date('Y-m-d');
        $monthStart = date('Y-m-01');
        $p = [':c' => $c, ':f' => $monthStart, ':t' => $today];

        $receivable = $this->reports->outstanding('tenant', $today);
        $payable = $this->reports->outstanding('vendor', $today);
        $billed = (string) $this->db->scalar(
            "SELECT COALESCE(SUM(total), 0) FROM invoices WHERE company_id = :c AND status <> 'cancelled' AND invoice_date BETWEEN :f AND :t",
            $p,
        );
        $collected = (string) $this->db->scalar(
            "SELECT COALESCE(SUM(amount), 0) FROM receipts WHERE company_id = :c AND status = 'active' AND receipt_date BETWEEN :f AND :t",
            $p,
        );
        $spent = Money::add(
            (string) $this->db->scalar(
                "SELECT COALESCE(SUM(total), 0) FROM purchases WHERE company_id = :c AND status <> 'cancelled' AND bill_date BETWEEN :f AND :t",
                $p,
            ),
            (string) $this->db->scalar(
                "SELECT COALESCE(SUM(total), 0) FROM expenses WHERE company_id = :c AND status = 'active' AND expense_date BETWEEN :f AND :t",
                $p,
            ),
        );
        $overdue = $this->db->one(
            "SELECT COUNT(*) AS count, COALESCE(SUM(total - paid_amount - credited_amount), 0) AS amount
             FROM invoices WHERE company_id = :c AND status IN ('unpaid','partial') AND due_date < :t",
            [':c' => $c, ':t' => $today],
        );
        $cashBank = $this->db->all(
            'SELECT a.id, a.name, COALESCE(SUM(vl.debit - vl.credit), 0) AS balance
             FROM accounts a LEFT JOIN voucher_lines vl ON vl.account_id = a.id
             WHERE a.company_id = :c AND a.is_cash_bank = 1 AND a.is_active = 1
             GROUP BY a.id, a.name ORDER BY a.code',
            [':c' => $c],
        );
        $units = $this->db->one(
            "SELECT COUNT(*) AS total,
                    SUM(EXISTS(SELECT 1 FROM agreements ag WHERE ag.unit_id = u.id AND ag.status = 'active')) AS occupied
             FROM units u WHERE u.company_id = :c AND u.is_active = 1",
            [':c' => $c],
        );
        $recent = $this->db->all(
            'SELECT i.id, i.number, i.invoice_date, i.total, i.status, t.name AS tenant_name
             FROM invoices i JOIN tenants t ON t.id = i.tenant_id WHERE i.company_id = :c ORDER BY i.id DESC LIMIT 5',
            [':c' => $c],
        );
        $due = $this->billing->due($today);

        return $this->response->success([
            'receivable' => $receivable['total'],
            'payable' => $payable['total'],
            'top_dues' => array_slice($receivable['rows'], 0, 5),
            'month' => ['billed' => Money::of($billed), 'collected' => Money::of($collected), 'spent' => $spent],
            'overdue' => ['count' => (int) ($overdue['count'] ?? 0), 'amount' => Money::of((string) ($overdue['amount'] ?? '0'))],
            'cash_bank' => $cashBank,
            'units' => ['total' => (int) ($units['total'] ?? 0), 'occupied' => (int) ($units['occupied'] ?? 0)],
            'bills_due' => count($due),
            'recent_invoices' => $recent,
        ]);
    }

    public function auditLogs(ServerRequestInterface $request): ResponseInterface
    {
        $this->context->requireRole(Context::ROLE_ADMIN);
        $q = $request->getQueryParams();
        $page = Page::fromRequest($request);
        $where = 'l.company_id = :c';
        $params = [':c' => $this->context->companyId()];
        if (!empty($q['entity'])) {
            $where .= ' AND l.entity = :e';
            $params[':e'] = (string) $q['entity'];
        }
        if (!empty($q['entity_id'])) {
            $where .= ' AND l.entity_id = :eid';
            $params[':eid'] = (int) $q['entity_id'];
        }
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM audit_logs l WHERE $where", $params);
        $items = $this->db->all(
            sprintf(
                "SELECT l.id, l.created_at, l.action, l.entity, l.entity_id, l.data, l.ip, u.name AS user_name
                 FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id WHERE $where ORDER BY l.id DESC LIMIT %d OFFSET %d",
                $page->perPage,
                $page->offset(),
            ),
            $params,
        );
        return $this->response->success(['items' => $items, 'total' => $total, 'page' => $page->page, 'per_page' => $page->perPage]);
    }
}
