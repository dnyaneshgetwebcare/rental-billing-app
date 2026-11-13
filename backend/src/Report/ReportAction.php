<?php

declare(strict_types=1);

namespace App\Report;

use App\Api\Shared\ResponseFactory;
use App\Shared\ApiException;
use App\Shared\Context;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * JSON reports; add ?format=xlsx to any of them for an Excel download.
 */
final readonly class ReportAction
{
    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    private const TAX = ['taxable_amount' => 'Taxable', 'cgst' => 'CGST', 'sgst' => 'SGST', 'igst' => 'IGST'];

    public function __construct(
        private ReportService $reports,
        private XlsxExporter $xlsx,
        private Context $context,
        private ResponseFactory $response,
    ) {}

    public function ledger(ServerRequestInterface $request): ResponseInterface
    {
        $this->context->requireRole(Context::ROLE_ACCOUNTANT);
        $q = $request->getQueryParams();
        $accountId = (int) ($q['account_id'] ?? 0);
        if ($accountId <= 0) {
            throw ApiException::validation(['account_id' => 'Choose an account.']);
        }
        [$from, $to] = $this->reports->range($q['from'] ?? null, $q['to'] ?? null);
        $partyType = in_array($q['party_type'] ?? null, ['tenant', 'vendor'], true) ? (string) $q['party_type'] : null;
        $partyId = $partyType !== null && !empty($q['party_id']) ? (int) $q['party_id'] : null;
        $data = $this->reports->ledger($accountId, $from, $to, $partyType, $partyId);
        if ($this->wantsXlsx($request)) {
            return $this->download('ledger-' . $data['account']['code'], [$this->statementSheet('Ledger ' . $data['account']['name'], $data)]);
        }
        return $this->response->success($data);
    }

    public function partyLedger(ServerRequestInterface $request): ResponseInterface
    {
        $this->context->requireRole(Context::ROLE_ACCOUNTANT);
        $q = $request->getQueryParams();
        [$from, $to] = $this->reports->range($q['from'] ?? null, $q['to'] ?? null);
        $data = $this->reports->partyLedger((string) ($q['party_type'] ?? ''), (int) ($q['party_id'] ?? 0), $from, $to);
        if ($this->wantsXlsx($request)) {
            return $this->download('statement-' . $data['party']['name'], [$this->statementSheet('Statement ' . $data['party']['name'], $data)]);
        }
        return $this->response->success($data);
    }

    public function trialBalance(ServerRequestInterface $request): ResponseInterface
    {
        $this->context->requireRole(Context::ROLE_ACCOUNTANT);
        $q = $request->getQueryParams();
        [, $asOf] = $this->reports->range(null, $q['as_of'] ?? null);
        $from = !empty($q['from']) ? $this->reports->range($q['from'], $asOf)[0] : null;
        $data = $this->reports->trialBalance($asOf, $from);
        if ($this->wantsXlsx($request)) {
            return $this->download('trial-balance-' . $asOf, [[
                'title' => 'Trial Balance',
                'subtitle' => ($from ? "$from to " : 'as of ') . $asOf,
                'columns' => ['code' => 'Code', 'name' => 'Account', 'type' => 'Type', 'debit' => 'Debit', 'credit' => 'Credit'],
                'money' => ['debit', 'credit'],
                'rows' => $data['rows'],
                'totals' => ['name' => 'Total', 'debit' => $data['total_debit'], 'credit' => $data['total_credit']],
            ]]);
        }
        return $this->response->success($data);
    }

    public function outstanding(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();
        $type = ($q['party_type'] ?? 'tenant') === 'vendor' ? 'vendor' : 'tenant';
        [, $asOf] = $this->reports->range(null, $q['as_of'] ?? null);
        $data = $this->reports->outstanding($type, $asOf);
        if ($this->wantsXlsx($request)) {
            return $this->download("outstanding-$type-$asOf", [[
                'title' => $type === 'tenant' ? 'Receivables' : 'Payables',
                'subtitle' => "as of $asOf",
                'columns' => [
                    'name' => 'Party', 'phone' => 'Phone', 'not_due' => 'Not due', 'd0_30' => '0-30 days', 'd31_60' => '31-60 days',
                    'd61_90' => '61-90 days', 'd90_plus' => '90+ days', 'balance' => 'Balance',
                ],
                'money' => ['not_due', 'd0_30', 'd31_60', 'd61_90', 'd90_plus', 'balance'],
                'rows' => $data['rows'],
                'totals' => ['name' => 'Total', 'balance' => $data['total']],
            ]]);
        }
        return $this->response->success($data);
    }

    public function salesRegister(ServerRequestInterface $request): ResponseInterface
    {
        $this->context->requireRole(Context::ROLE_ACCOUNTANT);
        $q = $request->getQueryParams();
        [$from, $to] = $this->reports->monthRange($q['month'] ?? null, $q['from'] ?? null, $q['to'] ?? null);
        $data = $this->reports->salesRegister($from, $to, !empty($q['include_cancelled']));
        if ($this->wantsXlsx($request)) {
            return $this->download("sales-register-$from-$to", [[
                'title' => 'Sales Register',
                'subtitle' => "$from to $to",
                'columns' => ['number' => 'Invoice', 'invoice_date' => 'Date', 'tenant_name' => 'Tenant', 'gstin' => 'GSTIN', 'place_of_supply' => 'POS']
                    + self::TAX + ['round_off' => 'Round off', 'total' => 'Total', 'status' => 'Status'],
                'money' => ['taxable_amount', 'cgst', 'sgst', 'igst', 'round_off', 'total'],
                'rows' => $data['rows'],
                'totals' => ['tenant_name' => 'Total'] + $data['totals'],
            ]]);
        }
        return $this->response->success($data);
    }

    public function gstSummary(ServerRequestInterface $request): ResponseInterface
    {
        $this->context->requireRole(Context::ROLE_ACCOUNTANT);
        $q = $request->getQueryParams();
        [$from, $to] = $this->reports->monthRange($q['month'] ?? null, $q['from'] ?? null, $q['to'] ?? null);
        $data = $this->reports->gstSummary($from, $to);
        if ($this->wantsXlsx($request)) {
            $money = ['taxable_amount', 'cgst', 'sgst', 'igst', 'invoice_value', 'total'];
            $g1 = $data['gstr1'];
            $g3 = $data['gstr3b'];
            return $this->download("gst-$from-$to", [
                [
                    'title' => 'B2B', 'subtitle' => "$from to $to", 'money' => $money, 'rows' => $g1['b2b'],
                    'columns' => ['gstin' => 'GSTIN', 'tenant_name' => 'Receiver', 'number' => 'Invoice', 'invoice_date' => 'Date',
                        'invoice_value' => 'Invoice value', 'place_of_supply' => 'POS', 'gst_rate' => 'Rate'] + self::TAX,
                ],
                [
                    'title' => 'B2C', 'subtitle' => "$from to $to", 'money' => $money, 'rows' => $g1['b2c'],
                    'columns' => ['place_of_supply' => 'POS', 'gst_rate' => 'Rate'] + self::TAX,
                ],
                [
                    'title' => 'Credit notes', 'subtitle' => "$from to $to", 'money' => $money, 'rows' => $g1['credit_notes'],
                    'columns' => ['gstin' => 'GSTIN', 'tenant_name' => 'Receiver', 'number' => 'Note', 'note_date' => 'Date',
                        'invoice_number' => 'Invoice', 'place_of_supply' => 'POS'] + self::TAX + ['total' => 'Total'],
                ],
                [
                    'title' => 'HSN-SAC', 'subtitle' => "$from to $to", 'money' => $money, 'rows' => $g1['hsn'],
                    'columns' => ['sac' => 'SAC', 'gst_rate' => 'Rate'] + self::TAX,
                ],
                [
                    'title' => 'GSTR-3B', 'subtitle' => "$from to $to", 'money' => $money,
                    'columns' => ['label' => 'Particulars'] + self::TAX + ['total' => 'Total tax'],
                    'rows' => [
                        ['label' => 'Outward supplies (net of credit notes)'] + $g3['output'],
                        ['label' => 'ITC - purchases'] + $g3['itc']['purchases'],
                        ['label' => 'ITC - expenses'] + $g3['itc']['expenses'],
                        ['label' => 'Total ITC', 'cgst' => $g3['itc']['cgst'], 'sgst' => $g3['itc']['sgst'], 'igst' => $g3['itc']['igst'], 'total' => $g3['itc']['total']],
                        ['label' => 'Net payable (before set-off rules)'] + $g3['net'],
                    ],
                ],
            ]);
        }
        return $this->response->success($data);
    }

    private function wantsXlsx(ServerRequestInterface $request): bool
    {
        return ($request->getQueryParams()['format'] ?? '') === 'xlsx';
    }

    /** @param array<string, mixed> $data */
    private function statementSheet(string $title, array $data): array
    {
        return [
            'title' => mb_substr($title, 0, 31),
            'subtitle' => "{$data['from']} to {$data['to']}",
            'columns' => [
                'voucher_date' => 'Date', 'number' => 'Voucher', 'voucher_type' => 'Type', 'narration' => 'Particulars',
                'party_name' => 'Party', 'debit' => 'Debit', 'credit' => 'Credit', 'balance' => 'Balance (Dr+)',
            ],
            'money' => ['debit', 'credit', 'balance'],
            'rows' => [['narration' => 'Opening balance', 'balance' => $data['opening']], ...$data['lines']],
            'totals' => ['narration' => 'Total', 'debit' => $data['total_debit'], 'credit' => $data['total_credit'], 'balance' => $data['closing']],
        ];
    }

    private function download(string $name, array $sheets): ResponseInterface
    {
        $content = $this->xlsx->export((string) $this->context->company()['name'], $sheets);
        return $this->response->file($content, self::XLSX, $name . '.xlsx');
    }
}
