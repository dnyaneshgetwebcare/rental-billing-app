<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Shared\ApiException;
use App\Shared\Context;
use App\Shared\Db;
use App\Shared\Money;
use App\Shared\NumberSeries;

/**
 * Turns documents into balanced double-entry vouchers. Documents are never edited in the ledger:
 * cancelling one posts a reversal voucher.
 */
final readonly class PostingService
{
    public function __construct(
        private Db $db,
        private Context $context,
        private ChartOfAccounts $chart,
        private NumberSeries $series,
    ) {}

    /**
     * @param list<array{account_id: int, debit?: string, credit?: string, party_type?: ?string, party_id?: ?int, narration?: ?string}> $lines
     */
    public function post(string $type, string $date, ?string $sourceType, ?int $sourceId, string $narration, array $lines): int
    {
        $lines = self::clean($lines);
        self::assertBalanced($lines);
        $company = $this->context->company();
        $companyId = (int) $company['id'];

        return $this->db->transaction(function () use ($type, $date, $sourceType, $sourceId, $narration, $lines, $company, $companyId): int {
            $voucherId = $this->db->insert('vouchers', [
                'company_id' => $companyId,
                'number' => $this->series->next($company, NumberSeries::VOUCHER, $date),
                'voucher_type' => $type,
                'voucher_date' => $date,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'narration' => mb_substr($narration, 0, 255),
                'created_by' => $this->context->userIdOrNull(),
                'created_at' => Db::now(),
            ]);
            foreach ($lines as $line) {
                $this->db->insert('voucher_lines', [
                    'company_id' => $companyId,
                    'voucher_id' => $voucherId,
                    'account_id' => $line['account_id'],
                    'party_type' => $line['party_type'] ?? null,
                    'party_id' => $line['party_id'] ?? null,
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'narration' => isset($line['narration']) ? mb_substr((string) $line['narration'], 0, 255) : null,
                ]);
            }
            return $voucherId;
        });
    }

    public function reverse(int $voucherId, string $date, string $narration): int
    {
        $companyId = $this->context->companyId();
        $voucher = $this->db->one(
            'SELECT * FROM vouchers WHERE id = :id AND company_id = :c FOR UPDATE',
            [':id' => $voucherId, ':c' => $companyId],
        ) ?? throw ApiException::notFound('Voucher');
        if ($voucher['is_reversed']) {
            throw ApiException::conflict('This voucher is already reversed.');
        }
        $lines = $this->db->all('SELECT * FROM voucher_lines WHERE voucher_id = :v', [':v' => $voucherId]);
        $reversed = array_map(static fn(array $l): array => [
            'account_id' => (int) $l['account_id'],
            'debit' => (string) $l['credit'],
            'credit' => (string) $l['debit'],
            'party_type' => $l['party_type'],
            'party_id' => $l['party_id'],
            'narration' => $l['narration'],
        ], $lines);

        return $this->db->transaction(function () use ($voucher, $voucherId, $date, $narration, $reversed): int {
            $id = $this->post('reversal', $date, $voucher['source_type'], $voucher['source_id'], $narration, $reversed);
            $this->db->update('vouchers', ['reverses_voucher_id' => $voucherId], ['id' => $id]);
            $this->db->update('vouchers', ['is_reversed' => 1], ['id' => $voucherId]);
            return $id;
        });
    }

    /**
     * Sales invoice: Dr tenant (debtors) total; Cr income per line; Cr output GST; round-off either side.
     *
     * @param array<string, mixed> $invoice
     * @param list<array<string, mixed>> $lines
     */
    public function invoiceLines(array $invoice, array $lines, bool $creditNote = false): array
    {
        $c = (int) $invoice['company_id'];
        $tenantId = (int) $invoice['tenant_id'];
        $out = [[
            'account_id' => $this->chart->idFor($c, ChartOfAccounts::DEBTORS),
            'debit' => (string) $invoice['total'],
            'party_type' => 'tenant',
            'party_id' => $tenantId,
        ]];
        $byAccount = [];
        foreach ($lines as $line) {
            $acc = (int) ($line['account_id'] ?? 0) ?: $this->chart->idFor($c, ChartOfAccounts::RENT_INCOME);
            $byAccount[$acc] = Money::add($byAccount[$acc] ?? '0', (string) $line['taxable_amount']);
        }
        foreach ($byAccount as $acc => $amount) {
            $out[] = ['account_id' => $acc, 'credit' => $amount];
        }
        foreach (['cgst' => ChartOfAccounts::OUTPUT_CGST, 'sgst' => ChartOfAccounts::OUTPUT_SGST, 'igst' => ChartOfAccounts::OUTPUT_IGST] as $col => $key) {
            $out[] = ['account_id' => $this->chart->idFor($c, $key), 'credit' => (string) $invoice[$col]];
        }
        $out[] = $this->roundOffLine($c, (string) $invoice['round_off'], incomeSide: true);

        return $creditNote ? self::swap($out) : $out;
    }

    /**
     * Purchase bill: Dr expense/asset per line; Dr input GST; Cr vendor (creditors) total.
     *
     * @param array<string, mixed> $purchase
     * @param list<array<string, mixed>> $lines
     */
    public function purchaseLines(array $purchase, array $lines): array
    {
        $c = (int) $purchase['company_id'];
        $out = [];
        $byAccount = [];
        foreach ($lines as $line) {
            $acc = (int) $line['account_id'];
            $byAccount[$acc] = Money::add($byAccount[$acc] ?? '0', (string) $line['taxable_amount']);
        }
        foreach ($byAccount as $acc => $amount) {
            $out[] = ['account_id' => $acc, 'debit' => $amount];
        }
        foreach (['cgst' => ChartOfAccounts::INPUT_CGST, 'sgst' => ChartOfAccounts::INPUT_SGST, 'igst' => ChartOfAccounts::INPUT_IGST] as $col => $key) {
            $out[] = ['account_id' => $this->chart->idFor($c, $key), 'debit' => (string) $purchase[$col]];
        }
        $out[] = $this->roundOffLine($c, (string) $purchase['round_off'], incomeSide: false);
        $out[] = [
            'account_id' => $this->chart->idFor($c, ChartOfAccounts::CREDITORS),
            'credit' => (string) $purchase['total'],
            'party_type' => 'vendor',
            'party_id' => (int) $purchase['vendor_id'],
        ];
        return $out;
    }

    /**
     * Expense paid immediately: Dr expense head + input GST; Cr cash/bank.
     *
     * @param array<string, mixed> $expense
     */
    public function expenseLines(array $expense): array
    {
        $c = (int) $expense['company_id'];
        return [
            ['account_id' => (int) $expense['account_id'], 'debit' => (string) $expense['amount']],
            ['account_id' => $this->chart->idFor($c, ChartOfAccounts::INPUT_CGST), 'debit' => (string) $expense['cgst']],
            ['account_id' => $this->chart->idFor($c, ChartOfAccounts::INPUT_SGST), 'debit' => (string) $expense['sgst']],
            ['account_id' => $this->chart->idFor($c, ChartOfAccounts::INPUT_IGST), 'debit' => (string) $expense['igst']],
            ['account_id' => (int) $expense['paid_from_account_id'], 'credit' => (string) $expense['total']],
        ];
    }

    /** Receipt from tenant: Dr cash/bank; Cr tenant (debtors). */
    public function receiptLines(int $companyId, int $tenantId, int $cashBankAccountId, string $amount): array
    {
        return [
            ['account_id' => $cashBankAccountId, 'debit' => $amount],
            [
                'account_id' => $this->chart->idFor($companyId, ChartOfAccounts::DEBTORS),
                'credit' => $amount,
                'party_type' => 'tenant',
                'party_id' => $tenantId,
            ],
        ];
    }

    /** Payment to vendor: Dr vendor (creditors); Cr cash/bank. */
    public function vendorPaymentLines(int $companyId, int $vendorId, int $cashBankAccountId, string $amount): array
    {
        return [
            [
                'account_id' => $this->chart->idFor($companyId, ChartOfAccounts::CREDITORS),
                'debit' => $amount,
                'party_type' => 'vendor',
                'party_id' => $vendorId,
            ],
            ['account_id' => $cashBankAccountId, 'credit' => $amount],
        ];
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    public static function assertBalanced(array $lines): void
    {
        $debit = $credit = '0.00';
        foreach ($lines as $line) {
            $debit = Money::add($debit, (string) $line['debit']);
            $credit = Money::add($credit, (string) $line['credit']);
        }
        if ($lines === [] || Money::isZero($debit)) {
            throw ApiException::validation(['lines' => 'A voucher needs at least one non-zero amount.']);
        }
        if (Money::cmp($debit, $credit) !== 0) {
            throw ApiException::validation(['lines' => "Debits ($debit) and credits ($credit) must be equal."]);
        }
    }

    /**
     * Normalizes amounts, turns negative amounts into the opposite side and drops zero lines.
     *
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    public static function clean(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $net = Money::sub(Money::of($line['debit'] ?? '0'), Money::of($line['credit'] ?? '0'));
            if (Money::isZero($net)) {
                continue;
            }
            $positive = Money::cmp($net, '0') > 0;
            $line['debit'] = $positive ? $net : '0.00';
            $line['credit'] = $positive ? '0.00' : Money::neg($net);
            $out[] = $line;
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    private static function swap(array $lines): array
    {
        return array_map(static function (array $l): array {
            $debit = $l['debit'] ?? '0';
            $l['debit'] = $l['credit'] ?? '0';
            $l['credit'] = $debit;
            return $l;
        }, $lines);
    }

    /**
     * Positive round-off on a sales document is extra income (credit); on a purchase it is extra cost (debit).
     *
     * @return array<string, mixed>
     */
    private function roundOffLine(int $companyId, string $roundOff, bool $incomeSide): array
    {
        $account = $this->chart->idFor($companyId, ChartOfAccounts::ROUND_OFF);
        $positive = Money::cmp($roundOff, '0') >= 0;
        $abs = $positive ? $roundOff : Money::neg($roundOff);
        $credit = $incomeSide === $positive;
        return ['account_id' => $account, 'debit' => $credit ? '0' : $abs, 'credit' => $credit ? $abs : '0'];
    }
}
