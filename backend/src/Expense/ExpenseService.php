<?php

declare(strict_types=1);

namespace App\Expense;

use App\Accounting\PostingService;
use App\Gst\GstCalculator;
use App\Receipt\ReceiptService;
use App\Shared\ApiException;
use App\Shared\AuditLog;
use App\Shared\CompanyScopedRepository;
use App\Shared\Db;
use App\Shared\Input;

/**
 * Expenses paid on the spot from cash or bank (electricity, repairs, salaries, ...).
 * Bills bought on credit go through purchases instead.
 */
final readonly class ExpenseService
{
    public function __construct(
        private CompanyScopedRepository $repo,
        private Db $db,
        private GstCalculator $gst,
        private PostingService $posting,
        private AuditLog $audit,
    ) {}

    public function create(Input $in): int
    {
        $date = $in->date('expense_date', default: date('Y-m-d'));
        $accountId = $in->int('account_id', required: true, min: 1);
        $paidFrom = $in->int('paid_from_account_id', required: true, min: 1);
        $vendorId = $in->int('vendor_id', min: 1);
        $description = $in->string('description', required: true, max: 255);
        $amount = $in->money('amount', required: true);
        $gstRate = $in->money('gst_rate', default: '0.00');
        $interState = $in->bool('is_inter_state');
        $mode = $in->enum('payment_mode', ReceiptService::MODES, default: 'cash');
        $reference = $in->string('reference', max: 100);
        $in->validate();

        $account = $this->repo->find('accounts', (int) $accountId);
        if ($account === null || !in_array($account['type'], ['expense', 'asset'], true) || $account['is_cash_bank']) {
            throw ApiException::validation(['account_id' => 'Choose an expense account.']);
        }
        $from = $this->repo->find('accounts', (int) $paidFrom);
        if ($from === null || !$from['is_cash_bank']) {
            throw ApiException::validation(['paid_from_account_id' => 'Choose a cash or bank account.']);
        }
        $this->repo->assertExists('vendors', $vendorId, 'vendor_id', 'Vendor');

        $calc = $this->gst->calculateLines(
            [['description' => (string) $description, 'qty' => '1', 'rate' => (string) $amount, 'gst_rate' => (string) $gstRate]],
            $interState,
        );
        $line = $calc->lines[0];

        return $this->db->transaction(function () use ($date, $account, $from, $vendorId, $description, $line, $gstRate, $interState, $mode, $reference): int {
            $row = [
                'expense_date' => $date,
                'account_id' => $account['id'],
                'paid_from_account_id' => $from['id'],
                'vendor_id' => $vendorId,
                'description' => $description,
                'amount' => $line['taxable_amount'],
                'gst_rate' => $gstRate,
                'is_inter_state' => $interState ? 1 : 0,
                'cgst' => $line['cgst'],
                'sgst' => $line['sgst'],
                'igst' => $line['igst'],
                'total' => $line['total'],
                'payment_mode' => $mode,
                'reference' => $reference,
                'status' => 'active',
            ];
            $id = $this->repo->insert('expenses', $row);
            $expense = $this->repo->get('expenses', $id, 'Expense');
            $voucherId = $this->posting->post('expense', (string) $date, 'expense', $id, (string) $description, $this->posting->expenseLines($expense));
            $this->repo->update('expenses', $id, ['voucher_id' => $voucherId]);
            $this->audit->record('create', 'expense', $id, $row);
            return $id;
        });
    }

    public function cancel(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $e = $this->repo->get('expenses', $id, 'Expense', forUpdate: true);
            if ($e['status'] === 'cancelled') {
                throw ApiException::conflict('Expense is already cancelled.');
            }
            if ($e['voucher_id'] !== null) {
                $this->posting->reverse((int) $e['voucher_id'], date('Y-m-d'), 'Cancelled expense: ' . $e['description']);
            }
            $this->repo->update('expenses', $id, ['status' => 'cancelled']);
            $this->audit->record('cancel', 'expense', $id);
        });
    }

    /** @return array<string, mixed> */
    public function detail(int $id): array
    {
        $e = $this->db->one(
            'SELECT e.*, a.name AS account_name, f.name AS paid_from_name, v.name AS vendor_name
             FROM expenses e JOIN accounts a ON a.id = e.account_id JOIN accounts f ON f.id = e.paid_from_account_id
             LEFT JOIN vendors v ON v.id = e.vendor_id WHERE e.id = :id AND e.company_id = :c',
            [':id' => $id, ':c' => $this->repo->companyId()],
        ) ?? throw ApiException::notFound('Expense');
        return $e;
    }
}
