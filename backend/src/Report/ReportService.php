<?php

declare(strict_types=1);

namespace App\Report;

use App\Accounting\ChartOfAccounts;
use App\Shared\ApiException;
use App\Shared\Context;
use App\Shared\Db;
use App\Shared\FinancialYear;
use App\Shared\Money;

/**
 * Read-only accounting and GST reports. Balances come from voucher_lines, so cancelled documents
 * (which post reversals) net out automatically.
 */
final readonly class ReportService
{
    public function __construct(
        private Db $db,
        private Context $context,
        private ChartOfAccounts $chart,
    ) {}

    /** @return array{0: string, 1: string} */
    public function range(?string $from, ?string $to): array
    {
        $to = self::validDate($to) ?? date('Y-m-d');
        $from = self::validDate($from) ?? FinancialYear::of($to, (int) ($this->context->company()['fy_start_month'] ?? 4))->start;
        if ($from > $to) {
            throw ApiException::validation(['from' => 'From date must be before to date.']);
        }
        return [$from, $to];
    }

    /** @return array{0: string, 1: string} */
    public function monthRange(?string $month, ?string $from, ?string $to): array
    {
        if ($month !== null && $month !== '') {
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
                throw ApiException::validation(['month' => 'Use YYYY-MM.']);
            }
            return ["$month-01", date('Y-m-t', strtotime("$month-01"))];
        }
        if ($from === null && $to === null) {
            return [date('Y-m-01'), date('Y-m-t')];
        }
        return $this->range($from, $to);
    }

    /**
     * Account (optionally one party) ledger with opening balance and running balance (positive = debit).
     *
     * @return array<string, mixed>
     */
    public function ledger(int $accountId, string $from, string $to, ?string $partyType = null, ?int $partyId = null): array
    {
        $c = $this->context->companyId();
        $account = $this->db->one('SELECT id, code, name, type FROM accounts WHERE id = :id AND company_id = :c', [':id' => $accountId, ':c' => $c])
            ?? throw ApiException::notFound('Account');
        $where = 'vl.company_id = :c AND vl.account_id = :a';
        $params = [':c' => $c, ':a' => $accountId];
        if ($partyType !== null && $partyId !== null) {
            $where .= ' AND vl.party_type = :pt AND vl.party_id = :pid';
            $params += [':pt' => $partyType, ':pid' => $partyId];
        }
        return ['account' => $account] + $this->statement($where, $params, $from, $to);
    }

    /**
     * Everything posted against one tenant/vendor across accounts (debtors, deposits, ...).
     *
     * @return array<string, mixed>
     */
    public function partyLedger(string $partyType, int $partyId, string $from, string $to): array
    {
        if (!in_array($partyType, ['tenant', 'vendor'], true)) {
            throw ApiException::validation(['party_type' => 'Choose tenant or vendor.']);
        }
        $c = $this->context->companyId();
        $party = $this->db->one(
            sprintf('SELECT id, name, gstin, phone, whatsapp, email, address FROM %s WHERE id = :id AND company_id = :c', $partyType . 's'),
            [':id' => $partyId, ':c' => $c],
        ) ?? throw ApiException::notFound(ucfirst($partyType));
        $result = $this->statement(
            'vl.company_id = :c AND vl.party_type = :pt AND vl.party_id = :pid',
            [':c' => $c, ':pt' => $partyType, ':pid' => $partyId],
            $from,
            $to,
        );
        return ['party' => $party, 'party_type' => $partyType] + $result;
    }

    /** @return array<string, mixed> */
    private function statement(string $where, array $params, string $from, string $to): array
    {
        $opening = Money::of((string) $this->db->scalar(
            "SELECT COALESCE(SUM(vl.debit - vl.credit), 0) FROM voucher_lines vl JOIN vouchers v ON v.id = vl.voucher_id
             WHERE $where AND v.voucher_date < :from",
            $params + [':from' => $from],
        ));
        $rows = $this->db->all(
            "SELECT v.id AS voucher_id, v.voucher_date, v.number, v.voucher_type, v.source_type, v.source_id,
                    COALESCE(vl.narration, v.narration) AS narration, a.name AS account_name,
                    CASE vl.party_type WHEN 'tenant' THEN t.name WHEN 'vendor' THEN vd.name END AS party_name,
                    vl.debit, vl.credit
             FROM voucher_lines vl
             JOIN vouchers v ON v.id = vl.voucher_id
             JOIN accounts a ON a.id = vl.account_id
             LEFT JOIN tenants t ON vl.party_type = 'tenant' AND t.id = vl.party_id
             LEFT JOIN vendors vd ON vl.party_type = 'vendor' AND vd.id = vl.party_id
             WHERE $where AND v.voucher_date BETWEEN :from AND :to
             ORDER BY v.voucher_date, v.id, vl.id",
            $params + [':from' => $from, ':to' => $to],
        );
        $balance = $opening;
        $debit = $credit = '0.00';
        foreach ($rows as &$row) {
            $balance = Money::add($balance, Money::sub((string) $row['debit'], (string) $row['credit']));
            $debit = Money::add($debit, (string) $row['debit']);
            $credit = Money::add($credit, (string) $row['credit']);
            $row['balance'] = $balance;
        }
        unset($row);
        return [
            'from' => $from,
            'to' => $to,
            'opening' => $opening,
            'lines' => $rows,
            'total_debit' => $debit,
            'total_credit' => $credit,
            'closing' => $balance,
        ];
    }

    /** @return array<string, mixed> */
    public function trialBalance(string $asOf, ?string $from = null): array
    {
        $params = [':c' => $this->context->companyId(), ':to' => $asOf];
        $dateWhere = 'v.voucher_date <= :to';
        if ($from !== null) {
            $dateWhere .= ' AND v.voucher_date >= :from';
            $params[':from'] = $from;
        }
        $rows = $this->db->all(
            "SELECT a.id AS account_id, a.code, a.name, a.type, COALESCE(SUM(vl.debit), 0) AS debit_total, COALESCE(SUM(vl.credit), 0) AS credit_total
             FROM accounts a
             JOIN voucher_lines vl ON vl.account_id = a.id
             JOIN vouchers v ON v.id = vl.voucher_id
             WHERE a.company_id = :c AND $dateWhere
             GROUP BY a.id, a.code, a.name, a.type
             ORDER BY a.code",
            $params,
        );
        $out = [];
        $totalDebit = $totalCredit = '0.00';
        $byType = [];
        foreach ($rows as $r) {
            $net = Money::sub((string) $r['debit_total'], (string) $r['credit_total']);
            if (Money::isZero($net)) {
                continue;
            }
            $debit = Money::cmp($net, '0') > 0 ? $net : '0.00';
            $credit = Money::cmp($net, '0') < 0 ? Money::neg($net) : '0.00';
            $totalDebit = Money::add($totalDebit, $debit);
            $totalCredit = Money::add($totalCredit, $credit);
            $byType[$r['type']] = Money::add($byType[$r['type']] ?? '0.00', $net);
            $out[] = [
                'account_id' => $r['account_id'], 'code' => $r['code'], 'name' => $r['name'], 'type' => $r['type'],
                'debit' => $debit, 'credit' => $credit,
            ];
        }
        $income = Money::neg($byType['income'] ?? '0.00');
        $expense = $byType['expense'] ?? '0.00';
        return [
            'as_of' => $asOf,
            'from' => $from,
            'rows' => $out,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'income' => $income,
            'expense' => $expense,
            'profit' => Money::sub($income, $expense),
        ];
    }

    /**
     * Party balances from the debtors/creditors ledger plus ageing of open documents by due date.
     *
     * @return array<string, mixed>
     */
    public function outstanding(string $partyType, string $asOf): array
    {
        $c = $this->context->companyId();
        $isTenant = $partyType === 'tenant';
        $control = $this->chart->idFor($c, $isTenant ? ChartOfAccounts::DEBTORS : ChartOfAccounts::CREDITORS);
        $table = $isTenant ? 'tenants' : 'vendors';
        $balances = $this->db->all(
            "SELECT p.id AS party_id, p.name, p.phone, p.whatsapp, SUM(vl.debit - vl.credit) AS balance
             FROM voucher_lines vl JOIN vouchers v ON v.id = vl.voucher_id
             JOIN $table p ON p.id = vl.party_id
             WHERE vl.company_id = :c AND vl.account_id = :a AND vl.party_type = :pt AND v.voucher_date <= :d
             GROUP BY p.id, p.name, p.phone, p.whatsapp
             HAVING balance <> 0
             ORDER BY p.name",
            [':c' => $c, ':a' => $control, ':pt' => $partyType, ':d' => $asOf],
        );

        $docs = $isTenant
            ? $this->db->all(
                "SELECT tenant_id AS party_id, COALESCE(due_date, invoice_date) AS due, total - paid_amount - credited_amount AS open
                 FROM invoices WHERE company_id = :c AND status IN ('unpaid','partial') AND invoice_date <= :d",
                [':c' => $c, ':d' => $asOf],
            )
            : $this->db->all(
                "SELECT vendor_id AS party_id, bill_date AS due, total - paid_amount AS open
                 FROM purchases WHERE company_id = :c AND status IN ('unpaid','partial') AND bill_date <= :d",
                [':c' => $c, ':d' => $asOf],
            );
        $ageing = [];
        $asOfTs = strtotime($asOf);
        foreach ($docs as $d) {
            $days = (int) floor(($asOfTs - strtotime((string) $d['due'])) / 86400);
            $bucket = match (true) {
                $days < 0 => 'not_due',
                $days <= 30 => 'd0_30',
                $days <= 60 => 'd31_60',
                $days <= 90 => 'd61_90',
                default => 'd90_plus',
            };
            $pid = (int) $d['party_id'];
            $ageing[$pid][$bucket] = Money::add($ageing[$pid][$bucket] ?? '0.00', (string) $d['open']);
        }

        $sign = $isTenant ? 1 : -1;
        $rows = [];
        $total = '0.00';
        foreach ($balances as $b) {
            $balance = $sign === 1 ? Money::of((string) $b['balance']) : Money::neg((string) $b['balance']);
            $row = [
                'party_id' => $b['party_id'], 'name' => $b['name'], 'phone' => $b['phone'], 'whatsapp' => $b['whatsapp'],
                'balance' => $balance,
            ];
            foreach (['not_due', 'd0_30', 'd31_60', 'd61_90', 'd90_plus'] as $k) {
                $row[$k] = $ageing[(int) $b['party_id']][$k] ?? '0.00';
            }
            $total = Money::add($total, $balance);
            $rows[] = $row;
        }
        usort($rows, static fn(array $a, array $b): int => Money::cmp($b['balance'], $a['balance']));
        return ['party_type' => $partyType, 'as_of' => $asOf, 'rows' => $rows, 'total' => $total];
    }

    /** @return array<string, mixed> */
    public function salesRegister(string $from, string $to, bool $includeCancelled = false): array
    {
        $rows = $this->db->all(
            "SELECT i.id, i.number, i.invoice_date, i.period_label, t.name AS tenant_name, t.gstin, i.place_of_supply,
                    i.taxable_amount, i.cgst, i.sgst, i.igst, i.round_off, i.total, i.paid_amount, i.credited_amount, i.status
             FROM invoices i JOIN tenants t ON t.id = i.tenant_id
             WHERE i.company_id = :c AND i.invoice_date BETWEEN :f AND :t" . ($includeCancelled ? '' : " AND i.status <> 'cancelled'") . '
             ORDER BY i.invoice_date, i.id',
            [':c' => $this->context->companyId(), ':f' => $from, ':t' => $to],
        );
        return ['from' => $from, 'to' => $to, 'rows' => $rows, 'totals' => self::sum($rows, ['taxable_amount', 'cgst', 'sgst', 'igst', 'round_off', 'total'], 'status')];
    }

    /**
     * GSTR-1 style outward supplies and GSTR-3B style summary (output tax, ITC, net payable).
     *
     * @return array<string, mixed>
     */
    public function gstSummary(string $from, string $to): array
    {
        $c = $this->context->companyId();
        $p = [':c' => $c, ':f' => $from, ':t' => $to];

        $b2b = $this->db->all(
            "SELECT i.id, i.number, i.invoice_date, t.gstin, t.name AS tenant_name, i.place_of_supply, il.gst_rate,
                    SUM(il.taxable_amount) AS taxable_amount, SUM(il.cgst) AS cgst, SUM(il.sgst) AS sgst, SUM(il.igst) AS igst, i.total AS invoice_value
             FROM invoices i JOIN invoice_lines il ON il.invoice_id = i.id JOIN tenants t ON t.id = i.tenant_id
             WHERE i.company_id = :c AND i.status <> 'cancelled' AND i.invoice_date BETWEEN :f AND :t AND t.gstin IS NOT NULL AND t.gstin <> ''
             GROUP BY i.id, i.number, i.invoice_date, t.gstin, t.name, i.place_of_supply, il.gst_rate, i.total
             ORDER BY i.invoice_date, i.id",
            $p,
        );
        $b2c = $this->db->all(
            "SELECT i.place_of_supply, il.gst_rate, SUM(il.taxable_amount) AS taxable_amount, SUM(il.cgst) AS cgst, SUM(il.sgst) AS sgst, SUM(il.igst) AS igst
             FROM invoices i JOIN invoice_lines il ON il.invoice_id = i.id JOIN tenants t ON t.id = i.tenant_id
             WHERE i.company_id = :c AND i.status <> 'cancelled' AND i.invoice_date BETWEEN :f AND :t AND (t.gstin IS NULL OR t.gstin = '')
             GROUP BY i.place_of_supply, il.gst_rate ORDER BY i.place_of_supply, il.gst_rate",
            $p,
        );
        $creditNotes = $this->db->all(
            "SELECT cn.id, cn.number, cn.note_date, i.number AS invoice_number, t.gstin, t.name AS tenant_name, cn.place_of_supply,
                    cn.taxable_amount, cn.cgst, cn.sgst, cn.igst, cn.total
             FROM credit_notes cn JOIN invoices i ON i.id = cn.invoice_id JOIN tenants t ON t.id = cn.tenant_id
             WHERE cn.company_id = :c AND cn.status = 'active' AND cn.note_date BETWEEN :f AND :t ORDER BY cn.note_date, cn.id",
            $p,
        );
        $hsn = $this->db->all(
            "SELECT COALESCE(il.sac, '') AS sac, il.gst_rate, SUM(il.taxable_amount) AS taxable_amount, SUM(il.cgst) AS cgst, SUM(il.sgst) AS sgst, SUM(il.igst) AS igst
             FROM invoices i JOIN invoice_lines il ON il.invoice_id = i.id
             WHERE i.company_id = :c AND i.status <> 'cancelled' AND i.invoice_date BETWEEN :f AND :t
             GROUP BY COALESCE(il.sac, ''), il.gst_rate ORDER BY sac, il.gst_rate",
            $p,
        );

        $cols = ['taxable_amount', 'cgst', 'sgst', 'igst'];
        $sales = self::sum($this->db->all(
            "SELECT taxable_amount, cgst, sgst, igst FROM invoices WHERE company_id = :c AND status <> 'cancelled' AND invoice_date BETWEEN :f AND :t",
            $p,
        ), $cols);
        $cn = self::sum($creditNotes, $cols);
        $purchases = self::sum($this->db->all(
            "SELECT taxable_amount, cgst, sgst, igst FROM purchases WHERE company_id = :c AND status <> 'cancelled' AND bill_date BETWEEN :f AND :t",
            $p,
        ), $cols);
        $expenses = self::sum($this->db->all(
            "SELECT amount AS taxable_amount, cgst, sgst, igst FROM expenses WHERE company_id = :c AND status = 'active' AND expense_date BETWEEN :f AND :t",
            $p,
        ), $cols);

        $output = [];
        $itc = [];
        $net = [];
        foreach ($cols as $col) {
            $output[$col] = Money::sub($sales[$col], $cn[$col]);
            $itc[$col] = Money::add($purchases[$col], $expenses[$col]);
        }
        foreach (['cgst', 'sgst', 'igst'] as $col) {
            $net[$col] = Money::sub($output[$col], $itc[$col]);
        }
        $output['total'] = Money::add(Money::add($output['cgst'], $output['sgst']), $output['igst']);
        $itc['total'] = Money::add(Money::add($itc['cgst'], $itc['sgst']), $itc['igst']);
        $net['total'] = Money::sub($output['total'], $itc['total']);

        return [
            'from' => $from,
            'to' => $to,
            'gstr1' => [
                'b2b' => $b2b,
                'b2c' => $b2c,
                'credit_notes' => $creditNotes,
                'hsn' => $hsn,
                'totals' => ['sales' => $sales, 'credit_notes' => $cn],
            ],
            'gstr3b' => [
                'output' => $output,
                'itc' => $itc + ['purchases' => $purchases, 'expenses' => $expenses],
                'net' => $net,
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $cols
     * @return array<string, string>
     */
    public static function sum(array $rows, array $cols, ?string $skipCancelledKey = null): array
    {
        $out = array_fill_keys($cols, '0.00');
        foreach ($rows as $r) {
            if ($skipCancelledKey !== null && ($r[$skipCancelledKey] ?? null) === 'cancelled') {
                continue;
            }
            foreach ($cols as $col) {
                $out[$col] = Money::add($out[$col], (string) ($r[$col] ?? '0'));
            }
        }
        return $out;
    }

    private static function validDate(?string $d): ?string
    {
        if ($d === null || $d === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $d);
        if ($dt === false || $dt->format('Y-m-d') !== $d) {
            throw ApiException::validation(['date' => "Invalid date $d. Use YYYY-MM-DD."]);
        }
        return $d;
    }
}
