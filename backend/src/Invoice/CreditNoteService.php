<?php

declare(strict_types=1);

namespace App\Invoice;

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

/**
 * Credit notes reduce an invoice's outstanding amount and reverse the matching income and output GST.
 */
final readonly class CreditNoteService
{
    public function __construct(
        private CompanyScopedRepository $repo,
        private Db $db,
        private Context $context,
        private GstCalculator $gst,
        private NumberSeries $series,
        private PostingService $posting,
        private InvoiceService $invoices,
        private AuditLog $audit,
    ) {}

    public function create(Input $in): int
    {
        $invoiceId = $in->int('invoice_id', required: true, min: 1);
        $date = $in->date('note_date', default: date('Y-m-d'));
        $reason = $in->string('reason', required: true, max: 255);
        $lineInputs = $in->list('lines', required: true, max: 50);
        $lines = [];
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

        return $this->db->transaction(function () use ($invoiceId, $date, $reason, $lines): int {
            $invoice = $this->repo->get('invoices', (int) $invoiceId, 'Invoice', forUpdate: true);
            if ($invoice['status'] === 'cancelled') {
                throw ApiException::conflict('Cannot issue a credit note against a cancelled invoice.');
            }
            $company = $this->context->company();
            $defaultAccount = $this->db->scalar(
                'SELECT account_id FROM invoice_lines WHERE invoice_id = :i ORDER BY sort LIMIT 1',
                [':i' => $invoice['id']],
            );
            foreach ($lines as $i => $line) {
                $lines[$i]['account_id'] = $line['account_id'] ?? ($defaultAccount === null ? null : (int) $defaultAccount);
            }

            $calc = $this->gst->calculate($lines, (string) $company['state_code'], (string) $invoice['place_of_supply']);
            $outstanding = Money::sub(
                Money::sub((string) $invoice['total'], (string) $invoice['paid_amount']),
                (string) $invoice['credited_amount'],
            );
            if (Money::cmp($calc->grandTotal, $outstanding) > 0) {
                throw ApiException::validation(['lines' => "Credit note total {$calc->grandTotal} is more than the invoice balance $outstanding."]);
            }

            $number = $this->series->next($company, NumberSeries::CREDIT_NOTE, (string) $date);
            $id = $this->repo->insert('credit_notes', [
                'invoice_id' => $invoice['id'],
                'tenant_id' => $invoice['tenant_id'],
                'number' => $number,
                'note_date' => $date,
                'reason' => $reason,
                'place_of_supply' => $invoice['place_of_supply'],
                'is_inter_state' => $calc->interState ? 1 : 0,
                'taxable_amount' => $calc->taxable,
                'cgst' => $calc->cgst,
                'sgst' => $calc->sgst,
                'igst' => $calc->igst,
                'round_off' => $calc->roundOff,
                'total' => $calc->grandTotal,
                'status' => 'active',
            ]);
            foreach ($calc->lines as $sort => $line) {
                $this->repo->insert('credit_note_lines', [
                    'credit_note_id' => $id,
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

            $note = $this->repo->get('credit_notes', $id, 'Credit note');
            $voucherId = $this->posting->post(
                'credit_note',
                (string) $date,
                'credit_note',
                $id,
                "Credit note $number against {$invoice['number']}",
                $this->posting->invoiceLines($note, $calc->lines, creditNote: true),
            );
            $this->repo->update('credit_notes', $id, ['voucher_id' => $voucherId]);
            $this->invoices->refreshStatus((int) $invoice['id']);
            $this->audit->record('create', 'credit_note', $id, ['number' => $number, 'invoice_id' => $invoice['id'], 'total' => $calc->grandTotal]);
            return $id;
        });
    }

    public function cancel(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $note = $this->repo->get('credit_notes', $id, 'Credit note', forUpdate: true);
            if ($note['status'] === 'cancelled') {
                throw ApiException::conflict('Credit note is already cancelled.');
            }
            if ($note['voucher_id'] !== null) {
                $this->posting->reverse((int) $note['voucher_id'], date('Y-m-d'), 'Cancelled credit note ' . $note['number']);
            }
            $this->repo->update('credit_notes', $id, ['status' => 'cancelled']);
            $this->invoices->refreshStatus((int) $note['invoice_id']);
            $this->audit->record('cancel', 'credit_note', $id);
        });
    }

    /** @return array<string, mixed> */
    public function detail(int $id): array
    {
        $note = $this->repo->get('credit_notes', $id, 'Credit note');
        $note['lines'] = $this->db->all('SELECT * FROM credit_note_lines WHERE credit_note_id = :id ORDER BY sort', [':id' => $id]);
        $note['invoice'] = $this->repo->find('invoices', (int) $note['invoice_id']);
        $note['tenant'] = $this->repo->find('tenants', (int) $note['tenant_id']);
        return $note;
    }
}
