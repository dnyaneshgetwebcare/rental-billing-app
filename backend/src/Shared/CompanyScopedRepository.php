<?php

declare(strict_types=1);

namespace App\Shared;

use Throwable;

/**
 * The one place that adds company_id to reads and writes of business tables.
 * Services must go through this (or add "company_id = :company" themselves in reports).
 */
final readonly class CompanyScopedRepository
{
    public function __construct(
        private Db $db,
        private Context $context,
    ) {}

    public function db(): Db
    {
        return $this->db;
    }

    public function companyId(): int
    {
        return $this->context->companyId();
    }

    /** @return array<string, mixed>|null */
    public function find(string $table, int $id, bool $forUpdate = false): ?array
    {
        return $this->db->one(
            sprintf('SELECT * FROM %s WHERE id = :id AND company_id = :company%s', Db::quote($table), $forUpdate ? ' FOR UPDATE' : ''),
            [':id' => $id, ':company' => $this->companyId()],
        );
    }

    /** @return array<string, mixed> */
    public function get(string $table, int $id, string $label = 'Record', bool $forUpdate = false): array
    {
        return $this->find($table, $id, $forUpdate) ?? throw ApiException::notFound($label);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function where(string $table, string $condition = '1 = 1', array $params = [], string $order = 'id'): array
    {
        return $this->db->all(
            sprintf('SELECT * FROM %s WHERE company_id = :company AND (%s) ORDER BY %s', Db::quote($table), $condition, $order),
            [':company' => $this->companyId()] + $params,
        );
    }

    /**
     * Paginated listing. $from may include joins; the main table must be aliased "t".
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function paginate(
        string $from,
        string $condition,
        array $params,
        string $order,
        Page $page,
        string $select = 't.*',
    ): array {
        $params = [':company' => $this->companyId()] + $params;
        $where = "t.company_id = :company AND ($condition)";
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM $from WHERE $where", $params);
        $items = $this->db->all(
            sprintf('SELECT %s FROM %s WHERE %s ORDER BY %s LIMIT %d OFFSET %d', $select, $from, $where, $order, $page->perPage, $page->offset()),
            $params,
        );
        return ['items' => $items, 'total' => $total, 'page' => $page->page, 'per_page' => $page->perPage];
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insert(string $table, array $row, bool $timestamps = true, bool $createdBy = true): int
    {
        $row['company_id'] = $this->companyId();
        if ($createdBy) {
            $row['created_by'] = $this->context->userIdOrNull();
        }
        if ($timestamps) {
            $row['created_at'] = $row['created_at'] ?? Db::now();
            $row['updated_at'] = Db::now();
        }
        return $this->db->insert($table, $row);
    }

    /**
     * @param array<string, mixed> $row
     */
    public function update(string $table, int $id, array $row, bool $timestamps = true): void
    {
        if ($timestamps) {
            $row['updated_at'] = Db::now();
        }
        $this->db->update($table, $row, ['id' => $id, 'company_id' => $this->companyId()]);
    }

    public function delete(string $table, int $id, string $label = 'Record'): void
    {
        try {
            $deleted = $this->db->delete($table, ['id' => $id, 'company_id' => $this->companyId()]);
        } catch (Throwable $e) {
            if (self::isForeignKeyError($e)) {
                throw ApiException::conflict("$label is in use and cannot be deleted. Mark it inactive instead.");
            }
            throw $e;
        }
        if ($deleted === 0) {
            throw ApiException::notFound($label);
        }
    }

    /** Throws 422 unless $id is a row of $table in the current company. */
    public function assertExists(string $table, ?int $id, string $field, string $label): void
    {
        if ($id === null) {
            return;
        }
        if ($this->find($table, $id) === null) {
            throw ApiException::validation([$field => "$label not found."]);
        }
    }

    public static function isForeignKeyError(Throwable $e): bool
    {
        $m = $e->getMessage();
        return str_contains($m, '1451') || str_contains($m, '1452') || stripos($m, 'foreign key constraint') !== false;
    }

    public static function isDuplicateError(Throwable $e): bool
    {
        return str_contains($e->getMessage(), '1062') || stripos($e->getMessage(), 'Duplicate entry') !== false;
    }
}
