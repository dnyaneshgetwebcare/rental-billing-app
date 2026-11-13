<?php

declare(strict_types=1);

namespace App\Attachment;

use App\Api\Shared\ResponseFactory;
use App\Shared\ApiException;
use App\Shared\AuditLog;
use App\Shared\CompanyScopedRepository;
use App\Shared\Context;
use App\Shared\Db;
use App\Shared\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * Photos/PDFs of bills, receipts and agreements attached to a document.
 */
final readonly class AttachmentAction
{
    /** owner_type => table */
    public const OWNERS = [
        'expense' => 'expenses',
        'purchase' => 'purchases',
        'invoice' => 'invoices',
        'receipt' => 'receipts',
        'agreement' => 'agreements',
        'tenant' => 'tenants',
        'vendor' => 'vendors',
        'vendor_payment' => 'vendor_payments',
    ];

    public function __construct(
        private ImageProcessor $images,
        private CompanyScopedRepository $repo,
        private Context $context,
        private AuditLog $audit,
        private ResponseFactory $response,
    ) {}

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();
        [$type, $id] = $this->owner((string) ($q['owner_type'] ?? ''), (int) ($q['owner_id'] ?? 0));
        $rows = $this->repo->where('attachments', 'owner_type = :t AND owner_id = :o', [':t' => $type, ':o' => $id], 'id DESC');
        return $this->response->success(array_map(self::present(...), $rows));
    }

    public function upload(ServerRequestInterface $request): ResponseInterface
    {
        $this->context->requireRole(Context::ROLE_ACCOUNTANT, Context::ROLE_STAFF);
        $body = (array) ($request->getParsedBody() ?? []);
        [$type, $id] = $this->owner((string) ($body['owner_type'] ?? ''), (int) ($body['owner_id'] ?? 0));
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            throw ApiException::validation(['file' => 'Choose a file.']);
        }
        $count = count($this->repo->where('attachments', 'owner_type = :t AND owner_id = :o', [':t' => $type, ':o' => $id]));
        if ($count >= 20) {
            throw ApiException::validation(['file' => 'A document can have at most 20 attachments.']);
        }

        $stored = $this->images->store($file, $this->repo->companyId());
        $attachmentId = $this->repo->insert(
            'attachments',
            ['owner_type' => $type, 'owner_id' => $id, 'created_at' => Db::now()] + $stored,
            timestamps: false,
        );
        $this->audit->record('upload', 'attachment', $attachmentId, ['owner_type' => $type, 'owner_id' => $id]);
        return $this->response->created(self::present($this->repo->get('attachments', $attachmentId, 'Attachment')));
    }

    public function delete(CurrentRoute $route): ResponseInterface
    {
        $this->context->requireRole(Context::ROLE_ACCOUNTANT);
        $id = (int) $route->getArgument('id');
        $row = $this->repo->get('attachments', $id, 'Attachment');
        $this->repo->delete('attachments', $id, 'Attachment');
        $this->images->delete([$row['path'], $row['thumb_path'], $row['medium_path']]);
        $this->audit->record('delete', 'attachment', $id, ['owner_type' => $row['owner_type'], 'owner_id' => $row['owner_id']]);
        return $this->response->success(['id' => $id]);
    }

    /** @return array{0: string, 1: int} */
    private function owner(string $type, int $id): array
    {
        $table = self::OWNERS[$type] ?? throw ApiException::validation(['owner_type' => 'Unknown document type.']);
        if ($id <= 0 || $this->repo->find($table, $id) === null) {
            throw ApiException::validation(['owner_id' => 'Document not found.']);
        }
        return [$type, $id];
    }

    /** @param array<string, mixed> $row */
    private static function present(array $row): array
    {
        $base = Settings::uploadUrl() . '/';
        $row['url'] = $base . $row['path'];
        $row['thumb_url'] = $row['thumb_path'] ? $base . $row['thumb_path'] : null;
        $row['medium_url'] = $row['medium_path'] ? $base . $row['medium_path'] : null;
        return $row;
    }
}
