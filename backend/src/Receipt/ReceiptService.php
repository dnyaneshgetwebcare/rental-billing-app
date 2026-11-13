<?php

declare(strict_types=1);

namespace App\Receipt;

use App\Accounting\PostingService;
use App\Invoice\InvoiceService;
use App\Shared\ApiException;
use App\Shared\AuditLog;
use App\Shared\CompanyScopedRepository;
use App\Shared\Context;
use App\Shared\Db;
use App\Shared\Input;
use App\Shared\Money;
use App\Shared\NumberSeries;

final readonly class ReceiptService
{
    public const MODES = ['cash', 'bank', 'upi', 'cheque'];

    public function __construct(
        private CompanyScopedRepository $repo,
        private Db $db,
        private Context $context,
        private NumberSeries $series,
        private PostingService $posting,
        private InvoiceService $invoices,
        private AuditLog $audit,
    ) {}

    /**
     * Without "allocations" the amount is applied to the tenant's oldest outstanding invoices.
     */
    public function create(Input $in): int
    {
        $tenantId = $in->int('tenant_id', required: true, min: 1);
        $date = $in->date('receipt_date', default: date('Y-m-d'));
        $amount = $in->money('amount', required: true);
        $mode = $in->enum('mode', self::MODES, default: 'bank');
        $accountId = $in->int('account_id', required: true, min: 1);
        $reference = $in->string('reference', max: 100);
        $notes = $in->string('notes', max: 1000);
        $allocInputs = $in->list('allocations', max: 100);
        $allocations = [];
        foreach ($allocInputs as $ai) {
            $allocations[] = [
                'invoice_id' => $ai->int('invoice_id', required: true, min: 1),
                'amount' => $ai->money('amount', required: true),
            ];
        }
        if ($amount !== null && Money::cmp($amount, '0') <= 0) {
            $in->error('amount', 'Must be more than zero.');
        }
        $in->validate($allocInputs);

        $tenant = $this->repo->find('tenants', (int) $tenantId) ?? throw ApiException::validation(['tenant_id' => 'Tenant not found.']);
        $account = $this->repo->find('accounts', (int) $accountId);
        if ($account === null || !$account['is_cash_bank']) {
            throw ApiException::validation(['account_id' => 'Choose a cash or bank account.']);
        }

        return $this->db->transaction(function () use ($tenant, $date, $amount, $mode, $account, $reference, $notes, $allocations, $allocInputs): int {
            $allocations = $allocInputs === []
                ? $this->autoAllocate((int) $tenant['id'], (string) $amount)
                : $this->checkAllocations((int) $tenant['id'], (string) $amount, $allocations);
            $allocated = Money::add('0', ...array_column($allocations, 'amount'));

            $number = $this->series->next($this->context->company(), NumberSeries::RECEIPT, (string) $date);
            $id = $this->repo->insert('receipts', [
                'tenant_id' => $tenant['id'],
                'number' => $number,
                'receipt_date' => $date,
                'amount' => $amount,
                'unallocated_amount' => Money::sub((string) $amount, $allocated),
                'mode' => $mode,
                'account_id' => $account['id'],
                'reference' => $reference,
                'notes' => $notes,
                'status' => 'active',
            ]);
            foreach ($allocations as $a) {
                $this->repo->insert('receipt_allocations', ['receipt_id' => $id] + $a, timestamps: false, createdBy: false);
            }

            $voucherId = $this->posting->post(
                'receipt',
                (string) $date,
                'receipt',
                $id,
                "Receipt $number from {$tenant['name']}" . ($reference ? " ($reference)" : ''),
                $this->posting->receiptLines($this->repo->companyId(), (int) $tenant['id'], (int) $account['id'], (string) $amount),
            );
            $this->repo->update('receipts', $id, ['voucher_id' => $voucherId]);
            foreach ($allocations as $a) {
                $this->invoices->refreshStatus((int) $a['invoice_id']);
            }
            $this->audit->record('create', 'receipt', $id, ['number' => $number, 'amount' => $amount, 'allocations' => $allocations]);
            return $id;
        });
    }

    public function cancel(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $receipt = $this->repo->get('receipts', $id, 'Receipt', forUpdate: true);
            if ($receipt['status'] === 'cancelled') {
                throw ApiException::conflict('Receipt is already cancelled.');
            }
            if ($receipt['voucher_id'] !== null) {
                $this->posting->reverse((int) $receipt['voucher_id'], date('Y-m-d'), 'Cancelled receipt ' . $receipt['number']);
            }
            $this->repo->update('receipts', $id, ['status' => 'cancelled']);
            foreach ($this->db->column('SELECT invoice_id FROM receipt_allocations WHERE receipt_id = :r', [':r' => $id]) as $invoiceId) {
                $this->invoices->refreshStatus((int) $invoiceId);
            }
            $this->audit->record('cancel', 'receipt', $id);
        });
    }

    /** @return array<string, mixed> */
    public function detail(int $id): array
    {
        $r = $this->repo->get('receipts', $id, 'Receipt');
        $r['tenant'] = $this->repo->find('tenants', (int) $r['tenant_id']);
        $r['account'] = $this->repo->find('accounts', (int) $r['account_id']);
        $r['allocations'] = $this->db->all(
            'SELECT ra.invoice_id, ra.amount, i.number, i.invoice_date, i.total
             FROM receipt_allocations ra JOIN invoices i ON i.id = ra.invoice_id WHERE ra.receipt_id = :r',
            [':r' => $id],
        );
        return $r;
    }

    /** @return list<array{invoice_id: int, amount: string}> */
    private function autoAllocate(int $tenantId, string $amount): array
    {
        $open = $this->db->all(
            "SELECT id, (total - paid_amount - credited_amount) AS balance FROM invoices
             WHERE company_id = :c AND tenant_id = :t AND status IN ('unpaid','partial')
             ORDER BY invoice_date, id FOR UPDATE",
            [':c' => $this->repo->companyId(), ':t' => $tenantId],
        );
        $left = $amount;
        $out = [];
        foreach ($open as $inv) {
            if (Money::cmp($left, '0') <= 0) {
                break;
            }
            $take = Money::min($left, (string) $inv['balance']);
            if (Money::cmp($take, '0') > 0) {
                $out[] = ['invoice_id' => (int) $inv['id'], 'amount' => $take];
                $left = Money::sub($left, $take);
            }
        }
        return $out;
    }

    /**
     * @param list<array{invoice_id: ?int, amount: ?string}> $allocations
     * @return list<array{invoice_id: int, amount: string}>
     */
    private function checkAllocations(int $tenantId, string $amount, array $allocations): array
    {
        $total = '0.00';
        $out = [];
        foreach ($allocations as $i => $a) {
            if (Money::isZero((string) $a['amount'])) {
                continue;
            }
            $inv = $this->repo->find('invoices', (int) $a['invoice_id'], forUpdate: true);
            if ($inv === null || (int) $inv['tenant_id'] !== $tenantId || $inv['status'] === 'cancelled') {
                throw ApiException::validation(["allocations.$i.invoice_id" => 'Invoice not found for this tenant.']);
            }
            $balance = Money::sub(Money::sub((string) $inv['total'], (string) $inv['paid_amount']), (string) $inv['credited_amount']);
            if (Money::cmp((string) $a['amount'], $balance) > 0) {
                throw ApiException::validation(["allocations.$i.amount" => "More than the invoice balance $balance."]);
            }
            $total = Money::add($total, (string) $a['amount']);
            $out[] = ['invoice_id' => (int) $inv['id'], 'amount' => (string) $a['amount']];
        }
        if (Money::cmp($total, $amount) > 0) {
            throw ApiException::validation(['allocations' => "Allocated $total is more than the receipt amount $amount."]);
        }
        return $out;
    }
}
