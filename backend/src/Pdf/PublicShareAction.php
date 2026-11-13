<?php

declare(strict_types=1);

namespace App\Pdf;

use App\Api\Shared\ResponseFactory;
use App\Invoice\InvoiceService;
use App\Shared\ApiException;
use App\Shared\Context;
use App\Shared\Db;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * GET /api/share/invoice/{id}?exp=&sig= — no login; opened by the tenant from WhatsApp.
 */
final readonly class PublicShareAction
{
    public function __construct(
        private ShareLink $share,
        private Db $db,
        private Context $context,
        private InvoiceService $invoices,
        private InvoicePdf $pdf,
        private ResponseFactory $response,
    ) {}

    public function invoice(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $id = (int) $route->getArgument('id');
        $q = $request->getQueryParams();
        if (!$this->share->verify('invoice', $id, (int) ($q['exp'] ?? 0), (string) ($q['sig'] ?? ''))) {
            throw new ApiException('This link has expired. Ask for a new one.', 'link_expired', 410);
        }
        $companyId = $this->db->scalar('SELECT company_id FROM invoices WHERE id = :id', [':id' => $id])
            ?? throw ApiException::notFound('Invoice');
        $company = $this->db->one('SELECT * FROM companies WHERE id = :id', [':id' => $companyId]) ?? throw ApiException::notFound('Invoice');
        $this->context->setCompany($company, Context::ROLE_STAFF);

        $invoice = $this->invoices->detail($id);
        if ($invoice['status'] === 'cancelled') {
            throw new ApiException('This invoice was cancelled.', 'cancelled', 410);
        }
        return $this->response->file(
            $this->pdf->render($company, $invoice),
            'application/pdf',
            'Invoice-' . $invoice['number'] . '.pdf',
            inline: true,
        );
    }
}
