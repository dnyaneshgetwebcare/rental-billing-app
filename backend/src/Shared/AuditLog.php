<?php

declare(strict_types=1);

namespace App\Shared;

final readonly class AuditLog
{
    public function __construct(
        private Db $db,
        private Context $context,
    ) {}

    /**
     * @param array<string, mixed>|null $data
     */
    public function record(string $action, string $entity, ?int $entityId, ?array $data = null, ?int $companyId = null): void
    {
        $this->db->insert('audit_logs', [
            'company_id' => $companyId ?? $this->companyIdOrNull(),
            'user_id' => $this->context->userIdOrNull(),
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'data' => $data === null ? null : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'ip' => isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : null,
            'created_at' => Db::now(),
        ]);
    }

    private function companyIdOrNull(): ?int
    {
        try {
            return $this->context->companyId();
        } catch (ApiException) {
            return null;
        }
    }
}
