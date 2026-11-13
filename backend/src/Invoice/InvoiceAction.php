<?php

declare(strict_types=1);

namespace App\Invoice;

use App\Api\Shared\ResponseFactory;
use App\Pdf\InvoicePdf;
use App\Pdf\ShareLink;
use App\Shared\ApiException;
use App\Shared\CompanyScopedRepository;
use App\Shared\Context;
use App\Shared\Db;
use App\Shared\Input;
use App\Shared\Page;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class InvoiceAction
{
    public function __construct(
        private InvoiceService $invoices,
        private CreditNoteService $creditNotes,
        private CompanyScopedRepository $repo,
        private Context $context,
        private Db $db,
        private InvoicePdf $pdf,
        private ShareLink $share,
        private ResponseFactory $response,
    ) {}

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();
        $conditions = ['1 = 1'];
        $params = [];
        if (!empty($q['status'])) {
            if ($q['status'] === 'outstanding') {
                $conditions[] = "t.status IN ('unpaid','partial')";
            } else {
                $conditions[] = 't.status = :status';
                $params[':status'] = (string) $q['status'];
            }
        }
        if (!empty($q['tenant_id'])) {
            $conditions[] = 't.tenant_id = :tenant';
            $params[':tenant'] = (int) $q['tenant_id'];
        }
        if (!empty($q['agreement_id'])) {
            $conditions[] = 't.agreement_id = :agreement';
            $params[':agreement'] = (int) $q['agreement_id'];
        }
        if (!empty($q['source'])) {
            $conditions[] = 't.source = :source';
            $params[':source'] = (string) $q['source'];
        }
        if (!empty($q['from'])) {
            $conditions[] = 't.invoice_date >= :from';
            $params[':from'] = (string) $q['from'];
        }
        if (!empty($q['to'])) {
            $conditions[] = 't.invoice_date <= :to';
            $params[':to'] = (string) $q['to'];
        }
        if (!empty($q['q'])) {
            $conditions[] = '(t.number LIKE :q OR tn.name LIKE :q)';
            $params[':q'] = '%' . addcslashes((string) $q['q'], '%_\\') . '%';
        }
        return $this->response->success($this->repo->paginate(
            'invoices t JOIN tenants tn ON tn.id = t.tenant_id',
            implode(' AND ', $conditions),
            $params,
            't.invoice_date DESC, t.id DESC',
            Page::fromRequest($request),
            't.id, t.number, t.invoice_date, t.due_date, t.period_label, t.total, t.paid_amount, t.credited_amount,
             (t.total - t.paid_amount - t.credited_amount) AS balance, t.status, t.source, t.tenant_id,
             tn.name AS tenant_name, tn.whatsapp AS tenant_whatsapp, tn.phone AS tenant_phone',
        ));
    }

    public function view(CurrentRoute $route): ResponseInterface
    {
        return $this->response->success($this->invoices->detail((int) $route->getArgument('id')));
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $this->context->requireRole(Context::ROLE_ACCOUNTANT, Context::ROLE_STAFF);
        $in = Input::fromRequest($request);
        $draft = $this->invoices->draftFromInput($in);
        $period = $in->period('period');
        $in->validate();
        if ($period !== null && $draft['header']['agreement_id'] === null) {
            throw ApiException::validation(['period' => 'A billing period needs an agreement.']);
        }
        try {
            $id = $this->invoices->create($draft['header'], $draft['lines'], 'manual', $period);
        } catch (\Throwable $e) {
            if (CompanyScopedRepository::isDuplicateError($e) && $period !== null) {
                throw ApiException::validation(['period' => 'This agreement is already billed for that month.']);
            }
            throw $e;
        }
        return $this->response->created($this->invoices->detail($id));
    }

    public function cancel(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $this->context->requireRole(Context::ROLE_ACCOUNTANT);
        $in = Input::fromRequest($request);
        $reason = $in->string('reason', required: true, max: 255);
        $in->validate();
        $id = (int) $route->getArgument('id');
        $this->invoices->cancel($id, (string) $reason);
        return $this->response->success($this->invoices->detail($id));
    }

    public function pdf(CurrentRoute $route): ResponseInterface
    {
        $invoice = $this->invoices->detail((int) $route->getArgument('id'));
        return $this->response->file(
            $this->pdf->render($this->context->company(), $invoice),
            'application/pdf',
            'Invoice-' . $invoice['number'] . '.pdf',
            inline: true,
        );
    }

    /** Signed link plus a ready WhatsApp message for the frontend share button. */
    public function shareLink(CurrentRoute $route): ResponseInterface
    {
        $invoice = $this->invoices->detail((int) $route->getArgument('id'));
        $url = $this->share->url('invoice', (int) $invoice['id']);
        $company = $this->context->company();
        $tenant = $invoice['tenant'] ?? [];
        $message = sprintf(
            "Dear %s,\nInvoice %s dated %s for Rs. %s from %s.%s\nView / download: %s",
            $tenant['name'] ?? 'Sir/Madam',
            $invoice['number'],
            date('d-m-Y', strtotime((string) $invoice['invoice_date'])),
            number_format((float) $invoice['total'], 2),
            $company['name'],
            $invoice['due_date'] ? "\nDue by " . date('d-m-Y', strtotime((string) $invoice['due_date'])) . '.' : '',
            $url,
        );
        $phone = preg_replace('/\D/', '', (string) (($tenant['whatsapp'] ?? '') ?: ($tenant['phone'] ?? '')));
        if ($phone !== null && strlen($phone) === 10) {
            $phone = '91' . $phone;
        }
        return $this->response->success([
            'url' => $url,
            'expires_in_days' => ShareLink::TTL_DAYS,
            'message' => $message,
            'phone' => $phone ?: null,
            'whatsapp_url' => 'https://wa.me/' . ($phone ?: '') . '?text=' . rawurlencode($message),
        ]);
    }

    public function creditNoteList(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();
        $conditions = ['1 = 1'];
        $params = [];
        if (!empty($q['invoice_id'])) {
            $conditions[] = 't.invoice_id = :inv';
            $params[':inv'] = (int) $q['invoice_id'];
        }
        if (!empty($q['tenant_id'])) {
            $conditions[] = 't.tenant_id = :tenant';
            $params[':tenant'] = (int) $q['tenant_id'];
        }
        return $this->response->success($this->repo->paginate(
            'credit_notes t JOIN tenants tn ON tn.id = t.tenant_id JOIN invoices i ON i.id = t.invoice_id',
            implode(' AND ', $conditions),
            $params,
            't.note_date DESC, t.id DESC',
            Page::fromRequest($request),
            't.id, t.number, t.note_date, t.reason, t.total, t.status, t.invoice_id, i.number AS invoice_number, tn.name AS tenant_name',
        ));
    }

    public function creditNoteView(CurrentRoute $route): ResponseInterface
    {
        return $this->response->success($this->creditNotes->detail((int) $route->getArgument('id')));
    }

    public function creditNoteCreate(ServerRequestInterface $request): ResponseInterface
    {
        $this->context->requireRole(Context::ROLE_ACCOUNTANT);
        $id = $this->creditNotes->create(Input::fromRequest($request));
        return $this->response->created($this->creditNotes->detail($id));
    }

    public function creditNoteCancel(CurrentRoute $route): ResponseInterface
    {
        $this->context->requireRole(Context::ROLE_ACCOUNTANT);
        $id = (int) $route->getArgument('id');
        $this->creditNotes->cancel($id);
        return $this->response->success($this->creditNotes->detail($id));
    }

    public function creditNotePdf(CurrentRoute $route): ResponseInterface
    {
        $note = $this->creditNotes->detail((int) $route->getArgument('id'));
        return $this->response->file(
            $this->pdf->render($this->context->company(), $note, creditNote: true),
            'application/pdf',
            'CreditNote-' . $note['number'] . '.pdf',
            inline: true,
        );
    }
}
