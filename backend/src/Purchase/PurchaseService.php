<?php

declare(strict_types=1);

namespace App\Purchase;

use App\Accounting\PostingService;
use App\Gst\GstCalculator;
use App\Receipt\ReceiptService;
use App\Shared\ApiException;
use App\Shared\AuditLog;
use App\Shared\CompanyScopedRepository;
use App\Shared\Context;
use App\Shared\Db;
use App\Shared\Input;
use App\Shared\Money;
use Throwable;

/**
 * Vendor bills (on credit) and payments against them. Input GST goes to the Input CGST/SGST/IGST ledgers.
 */
final readonly class PurchaseService
{
    public function __construct(
        private CompanyScopedRepository $repo,
        private Db $db,
        private Context $context,
        private GstCalculator $gst,
        private PostingService $posting,
        private AuditLog $audit,
    ) {}

    public function create(Input $in): int
    {
        $vendorId = $in->int('vendor_id', required: true, min: 1);
        $billNo = $in->string('bill_no', required: true, max: 30);
        $billDate = $in->date('bill_date', required: true);
        $pos = $in->stateCode('place_of_supply');
        $notes = $in->string('notes', max: 2000);
        $lineInputs = $in->list('lines', required: true, max: 50);
        $lines = [];
        foreach ($lineInputs as $li) {
            $lines[] = [
                'account_id' => $li->int('account_id', required: true, min: 1),
                'description' => $li->string('description', required: true, max: 255),
                'sac' => $li->string('sac', max: 10),
                'qty' => $li->money('qty', default: '1', scale: 3),
                'rate' => $li->money('rate', required: true),
                'gst_rate' => $li->money('gst_rate', default: '0.00'),
            ];
        }
        $in->validate($lineInputs);

        $vendor = $this->repo->find('vendors', (int) $vendorId) ?? throw ApiException::validation(['vendor_id' => 'Vendor not found.']);
        foreach ($lines as $i => $line) {
            $acc = $this->repo->find('accounts', (int) $line['account_id']);
            if ($acc === null || !in_array($acc['type'], ['expense', 'asset'], true) || $acc['is_cash_bank']) {
                throw ApiException::validation(["lines.$i.account_id" => 'Choose an expense or asset account.']);
            }
        }

        $company = $this->context->company();
        $pos ??= (string) $vendor['state_code'];
        $calc = $this->gst->calculate($lines, $pos, (string) $company['state_code']);

        try {
            return $this->db->transaction(function () use ($vendor, $billNo, $billDate, $pos, $notes, $calc): int {
                $id = $this->repo->insert('purchases', [
                    'vendor_id' => $vendor['id'],
                    'bill_no' => $billNo,
                    'bill_date' => $billDate,
                    'place_of_supply' => $pos,
                    'is_inter_state' => $calc->interState ? 1 : 0,
                    'taxable_amount' => $calc->taxable,
                    'cgst' => $calc->cgst,
                    'sgst' => $calc->sgst,
                    'igst' => $calc->igst,
                    'round_off' => $calc->roundOff,
                    'total' => $calc->grandTotal,
                    'status' => 'unpaid',
                    'notes' => $notes,
                ]);
                foreach ($calc->lines as $sort => $line) {
                    $this->repo->insert('purchase_lines', [
                        'purchase_id' => $id,
                        'account_id' => $line['account_id'],
                        'description' => $line['description'],
                        'sac' => $line['sac'] ?: null,
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
                $purchase = $this->repo->get('purchases', $id, 'Purchase');
                $voucherId = $this->posting->post(
                    'purchase',
                    (string) $billDate,
                    'purchase',
                    $id,
                    "Bill $billNo from {$vendor['name']}",
                    $this->posting->purchaseLines($purchase, $calc->lines),
                );
                $this->repo->update('purchases', $id, ['voucher_id' => $voucherId]);
                $this->audit->record('create', 'purchase', $id, ['bill_no' => $billNo, 'total' => $calc->grandTotal]);
                return $id;
            });
        } catch (Throwable $e) {
            if (CompanyScopedRepository::isDuplicateError($e)) {
                throw ApiException::validation(['bill_no' => 'This bill number is already entered for the vendor.']);
            }
            throw $e;
        }
    }

    public function cancel(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $p = $this->repo->get('purchases', $id, 'Purchase', forUpdate: true);
            if ($p['status'] === 'cancelled') {
                throw ApiException::conflict('Purchase is already cancelled.');
            }
            if (Money::cmp((string) $p['paid_amount'], '0') > 0) {
                throw ApiException::conflict('Cancel the payments against this bill first.');
            }
            if ($p['voucher_id'] !== null) {
                $this->posting->reverse((int) $p['voucher_id'], date('Y-m-d'), 'Cancelled bill ' . $p['bill_no']);
            }
            $this->repo->update('purchases', $id, ['status' => 'cancelled']);
            $this->audit->record('cancel', 'purchase', $id);
        });
    }

    public function createPayment(Input $in): int
    {
        $vendorId = $in->int('vendor_id', required: true, min: 1);
        $purchaseId = $in->int('purchase_id', min: 1);
        $date = $in->date('payment_date', default: date('Y-m-d'));
        $amount = $in->money('amount', required: true);
        $accountId = $in->int('account_id', required: true, min: 1);
        $mode = $in->enum('mode', ReceiptService::MODES, default: 'bank');
        $reference = $in->string('reference', max: 100);
        if ($amount !== null && Money::cmp($amount, '0') <= 0) {
            $in->error('amount', 'Must be more than zero.');
        }
        $in->validate();

        $vendor = $this->repo->find('vendors', (int) $vendorId) ?? throw ApiException::validation(['vendor_id' => 'Vendor not found.']);
        $account = $this->repo->find('accounts', (int) $accountId);
        if ($account === null || !$account['is_cash_bank']) {
            throw ApiException::validation(['account_id' => 'Choose a cash or bank account.']);
        }

        return $this->db->transaction(function () use ($vendor, $purchaseId, $date, $amount, $account, $mode, $reference): int {
            if ($purchaseId !== null) {
                $p = $this->repo->find('purchases', $purchaseId, forUpdate: true);
                if ($p === null || (int) $p['vendor_id'] !== (int) $vendor['id'] || $p['status'] === 'cancelled') {
                    throw ApiException::validation(['purchase_id' => 'Bill not found for this vendor.']);
                }
                $balance = Money::sub((string) $p['total'], (string) $p['paid_amount']);
                if (Money::cmp((string) $amount, $balance) > 0) {
                    throw ApiException::validation(['amount' => "More than the bill balance $balance."]);
                }
            }
            $id = $this->repo->insert('vendor_payments', [
                'vendor_id' => $vendor['id'],
                'purchase_id' => $purchaseId,
                'payment_date' => $date,
                'amount' => $amount,
                'account_id' => $account['id'],
                'mode' => $mode,
                'reference' => $reference,
                'status' => 'active',
            ]);
            $voucherId = $this->posting->post(
                'payment',
                (string) $date,
                'vendor_payment',
                $id,
                "Payment to {$vendor['name']}" . ($reference ? " ($reference)" : ''),
                $this->posting->vendorPaymentLines($this->repo->companyId(), (int) $vendor['id'], (int) $account['id'], (string) $amount),
            );
            $this->repo->update('vendor_payments', $id, ['voucher_id' => $voucherId]);
            if ($purchaseId !== null) {
                $this->refreshStatus($purchaseId);
            }
            $this->audit->record('create', 'vendor_payment', $id, ['amount' => $amount, 'purchase_id' => $purchaseId]);
            return $id;
        });
    }

    public function cancelPayment(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $pay = $this->repo->get('vendor_payments', $id, 'Payment', forUpdate: true);
            if ($pay['status'] === 'cancelled') {
                throw ApiException::conflict('Payment is already cancelled.');
            }
            if ($pay['voucher_id'] !== null) {
                $this->posting->reverse((int) $pay['voucher_id'], date('Y-m-d'), 'Cancelled vendor payment #' . $id);
            }
            $this->repo->update('vendor_payments', $id, ['status' => 'cancelled']);
            if ($pay['purchase_id'] !== null) {
                $this->refreshStatus((int) $pay['purchase_id']);
            }
            $this->audit->record('cancel', 'vendor_payment', $id);
        });
    }

    public function refreshStatus(int $purchaseId): void
    {
        $p = $this->repo->get('purchases', $purchaseId, 'Purchase', forUpdate: true);
        if ($p['status'] === 'cancelled') {
            return;
        }
        $paid = Money::of((string) $this->db->scalar(
            "SELECT COALESCE(SUM(amount), 0) FROM vendor_payments WHERE purchase_id = :p AND status = 'active'",
            [':p' => $purchaseId],
        ));
        $status = match (true) {
            Money::cmp($paid, (string) $p['total']) >= 0 => 'paid',
            Money::cmp($paid, '0') > 0 => 'partial',
            default => 'unpaid',
        };
        $this->repo->update('purchases', $purchaseId, ['paid_amount' => $paid, 'status' => $status]);
    }

    /** @return array<string, mixed> */
    public function detail(int $id): array
    {
        $p = $this->repo->get('purchases', $id, 'Purchase');
        $p['balance'] = Money::sub((string) $p['total'], (string) $p['paid_amount']);
        $p['vendor'] = $this->repo->find('vendors', (int) $p['vendor_id']);
        $p['lines'] = $this->db->all(
            'SELECT pl.*, a.name AS account_name FROM purchase_lines pl JOIN accounts a ON a.id = pl.account_id
             WHERE pl.purchase_id = :id ORDER BY pl.sort',
            [':id' => $id],
        );
        $p['payments'] = $this->db->all(
            'SELECT vp.id, vp.payment_date, vp.amount, vp.mode, vp.reference, vp.status, a.name AS account_name
             FROM vendor_payments vp JOIN accounts a ON a.id = vp.account_id WHERE vp.purchase_id = :id ORDER BY vp.payment_date',
            [':id' => $id],
        );
        return $p;
    }
}
