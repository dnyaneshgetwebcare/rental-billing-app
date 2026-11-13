<?php

declare(strict_types=1);

namespace App\Invoice;

use App\Accounting\ChartOfAccounts;
use App\Accounting\PostingService;
use App\Gst\GstCalculator;
use App\Shared\ApiException;
use App\Shared\AuditLog;
use App\Shared\CompanyScopedRepository;
use App\Shared\Context;
use App\Shared\Db;
use App\Shared\Input;
use App\Shared\Money;
use App\Shared\NumberSeries;
use DateTimeImmutable;

final readonly class InvoiceService
{
    public function __construct(
        private CompanyScopedRepository $repo,
        private Db $db,
        private Context $context,
        private GstCalculator $gst,
        private NumberSeries $series,
        private PostingService $posting,
        private ChartOfAccounts $chart,
        private AuditLog $audit,
    ) {}

    /**
     * Validates request data into an invoice draft (not saved).
     *
     * @return array{header: array<string, mixed>, lines: list<array<string, mixed>>}
     */
    public function draftFromInput(Input $in): array
    {
        $tenantId = $in->int('tenant_id', required: true, min: 1);
        $agreementId = $in->int('agreement_id', min: 1);
        $date = $in->date('invoice_date', default: date('Y-m-d'));
        $dueDate = $in->date('due_date');
        $pos = $in->stateCode('place_of_supply');
        $notes = $in->string('notes', max: 2000);

        $lines = [];
        $lineInputs = $in->list('lines', required: true, max: 50);
        foreach ($lineInputs as $li) {
            $lines[] = [
                'description' => $li->string('description', required: true, max: 255),
                'sac' => $li->string('sac', max: 10, default: '997212'),
                'qty' => $li->money('qty', default: '1', scale: 3),
                'rate' => $li->money('rate', required: true),
                'gst_rate' => $li->money('gst_rate', default: '18.00'),
                'account_id' => $li->int('account_id', min: 1),
            ];
        }
        $in->validate($lineInputs);

        return [
            'header' => [
                'tenant_id' => (int) $tenantId,
                'agreement_id' => $agreementId,
                'invoice_date' => (string) $date,
                'due_date' => $dueDate,
                'place_of_supply' => $pos,
                'notes' => $notes,
            ],
            'lines' => $lines,
        ];
    }

    /**
     * Creates and posts an invoice. $period (YYYY-MM) is set for agreement billing; the unique key on
     * (agreement_id, period) makes auto-billing safe to re-run.
     *
     * @param array<string, mixed> $header
     * @param list<array<string, mixed>> $lines
     */
    public function create(array $header, array $lines, string $source = 'manual', ?string $period = null): int
    {
        $company = $this->context->company();
        $companyId = (int) $company['id'];
        $tenant = $this->repo->find('tenants', (int) $header['tenant_id']);
        if ($tenant === null) {
            throw ApiException::validation(['tenant_id' => 'Tenant not found.']);
        }

        $agreement = null;
        if (!empty($header['agreement_id'])) {
            $agreement = $this->repo->find('agreements', (int) $header['agreement_id']);
            if ($agreement === null || (int) $agreement['tenant_id'] !== (int) $tenant['id']) {
                throw ApiException::validation(['agreement_id' => 'Agreement not found for this tenant.']);
            }
        }

        $pos = $header['place_of_supply'] ?? null;
        $pos ??= $agreement !== null ? $this->propertyState((int) $agreement['unit_id']) : null;
        $pos ??= (string) $tenant['state_code'];

        foreach ($lines as $i => $line) {
            if (!empty($line['account_id'])) {
                $acc = $this->repo->find('accounts', (int) $line['account_id']);
                if ($acc === null || $acc['type'] !== 'income') {
                    throw ApiException::validation(["lines.$i.account_id" => 'Choose an income account.']);
                }
            } else {
                $lines[$i]['account_id'] = $this->chart->idFor($companyId, ChartOfAccounts::RENT_INCOME);
            }
        }

        $calc = $this->gst->calculate($lines, (string) $company['state_code'], $pos);
        if (Money::cmp($calc->grandTotal, '0') <= 0) {
            throw ApiException::validation(['lines' => 'Invoice total must be more than zero.']);
        }

        $date = (string) $header['invoice_date'];
        $dueDate = $header['due_date'] ?? null;
        if ($dueDate === null) {
            $days = $agreement !== null ? (int) $agreement['due_days'] : 7;
            $dueDate = (new DateTimeImmutable($date))->modify("+$days days")->format('Y-m-d');
        }

        return $this->db->transaction(function () use ($company, $header, $calc, $date, $dueDate, $pos, $source, $period, $tenant): int {
            $row = [
                'tenant_id' => (int) $tenant['id'],
                'agreement_id' => $header['agreement_id'] ?? null,
                'period' => $period,
                'period_label' => $period,
                'number' => $this->series->next($company, NumberSeries::INVOICE, $date),
                'invoice_date' => $date,
                'due_date' => $dueDate,
                'place_of_supply' => $pos,
                'is_inter_state' => $calc->interState ? 1 : 0,
                'taxable_amount' => $calc->taxable,
                'cgst' => $calc->cgst,
                'sgst' => $calc->sgst,
                'igst' => $calc->igst,
                'round_off' => $calc->roundOff,
                'total' => $calc->grandTotal,
                'status' => 'unpaid',
                'source' => $source,
                'notes' => $header['notes'] ?? null,
            ];
            $id = $this->repo->insert('invoices', $row);
            foreach ($calc->lines as $sort => $line) {
                $this->repo->insert('invoice_lines', [
                    'invoice_id' => $id,
                    'account_id' => $line['account_id'] ?? null,
                    'description' => $line['description'],
                    'sac' => $line['sac'],
                    'qty' => $line['qty'],
                    'rate' => $line['rate'],
                    'gst_rate' => $line['gst_rate'],
                    'taxable_amount' => $line['taxable_amount'],
                    'cgst' => $line['cgst'],
                    'sgst' => $line['sgst'],
                    'igst' => $line['igst'],
                    'total' => $line['total'],
                    'sort' => $sort,
                ], timestamps: false, createdBy: false);
            }

            $invoice = $this->repo->get('invoices', $id, 'Invoice');
            $voucherId = $this->posting->post(
                'sales',
                $date,
                'invoice',
                $id,
                'Invoice ' . $row['number'] . ' - ' . $tenant['name'],
                $this->posting->invoiceLines($invoice, $calc->lines),
            );
            $this->repo->update('invoices', $id, ['voucher_id' => $voucherId]);
            $this->audit->record('create', 'invoice', $id, ['number' => $row['number'], 'total' => $row['total'], 'source' => $source]);
            return $id;
        });
    }

    public function cancel(int $id, string $reason): void
    {
        $this->db->transaction(function () use ($id, $reason): void {
            $invoice = $this->repo->get('invoices', $id, 'Invoice', forUpdate: true);
            if ($invoice['status'] === 'cancelled') {
                throw ApiException::conflict('Invoice is already cancelled.');
            }
            if (Money::cmp((string) $invoice['paid_amount'], '0') > 0) {
                throw ApiException::conflict('Cancel the receipts against this invoice first.');
            }
            if (Money::cmp((string) $invoice['credited_amount'], '0') > 0) {
                throw ApiException::conflict('This invoice has credit notes. Cancel them first.');
            }
            if ($invoice['voucher_id'] !== null) {
                $this->posting->reverse((int) $invoice['voucher_id'], date('Y-m-d'), 'Cancelled invoice ' . $invoice['number']);
            }
            $this->repo->update('invoices', $id, [
                'status' => 'cancelled',
                'period' => null,
                'cancelled_at' => Db::now(),
                'cancel_reason' => $reason,
            ]);
            $this->audit->record('cancel', 'invoice', $id, ['reason' => $reason]);
        });
    }

    /** Recomputes unpaid / partial / paid from receipts and credit notes. */
    public function refreshStatus(int $id): void
    {
        $inv = $this->repo->get('invoices', $id, 'Invoice', forUpdate: true);
        if ($inv['status'] === 'cancelled') {
            return;
        }
        $paid = (string) $this->db->scalar(
            "SELECT COALESCE(SUM(ra.amount), 0) FROM receipt_allocations ra JOIN receipts r ON r.id = ra.receipt_id
             WHERE ra.invoice_id = :id AND r.status = 'active'",
            [':id' => $id],
        );
        $credited = (string) $this->db->scalar(
            "SELECT COALESCE(SUM(total), 0) FROM credit_notes WHERE invoice_id = :id AND status = 'active'",
            [':id' => $id],
        );
        $due = Money::sub((string) $inv['total'], Money::of($credited));
        $paid = Money::of($paid);
        $status = match (true) {
            Money::cmp($paid, $due) >= 0 => 'paid',
            Money::cmp($paid, '0') > 0 => 'partial',
            default => 'unpaid',
        };
        $this->repo->update('invoices', $id, ['paid_amount' => $paid, 'credited_amount' => Money::of($credited), 'status' => $status]);
    }

    /** @return array<string, mixed> */
    public function detail(int $id): array
    {
        $invoice = $this->repo->get('invoices', $id, 'Invoice');
        $invoice['balance'] = Money::sub(Money::sub((string) $invoice['total'], (string) $invoice['paid_amount']), (string) $invoice['credited_amount']);
        $invoice['lines'] = $this->db->all('SELECT * FROM invoice_lines WHERE invoice_id = :id ORDER BY sort', [':id' => $id]);
        $invoice['tenant'] = $this->repo->find('tenants', (int) $invoice['tenant_id']);
        $invoice['receipts'] = $this->db->all(
            'SELECT r.id, r.number, r.receipt_date, r.mode, r.status, ra.amount
             FROM receipt_allocations ra JOIN receipts r ON r.id = ra.receipt_id WHERE ra.invoice_id = :id ORDER BY r.receipt_date',
            [':id' => $id],
        );
        $invoice['credit_notes'] = $this->db->all(
            'SELECT id, number, note_date, reason, total, status FROM credit_notes WHERE invoice_id = :id ORDER BY note_date',
            [':id' => $id],
        );
        if ($invoice['agreement_id'] !== null) {
            $invoice['unit'] = $this->db->one(
                'SELECT u.code, u.area_sqft, w.name AS warehouse_name, w.address AS warehouse_address
                 FROM agreements a JOIN units u ON u.id = a.unit_id JOIN warehouses w ON w.id = u.warehouse_id WHERE a.id = :a',
                [':a' => $invoice['agreement_id']],
            );
        }
        return $invoice;
    }

    private function propertyState(int $unitId): ?string
    {
        $state = $this->db->scalar(
            'SELECT w.state_code FROM units u JOIN warehouses w ON w.id = u.warehouse_id WHERE u.id = :u',
            [':u' => $unitId],
        );
        return $state === null || $state === '' ? null : (string) $state;
    }
}
