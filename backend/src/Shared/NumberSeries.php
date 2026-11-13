<?php

declare(strict_types=1);

namespace App\Shared;

use LogicException;

/**
 * Gap-free document numbers per company, document type and financial year, e.g. ABC/26-27/0001.
 * Must run inside the transaction that saves the document, so a rollback releases the number.
 */
final readonly class NumberSeries
{
    public const INVOICE = 'INV';
    public const CREDIT_NOTE = 'CN';
    public const RECEIPT = 'RCT';
    public const VOUCHER = 'V';

    public function __construct(private Db $db) {}

    /**
     * @param array<string, mixed> $company
     */
    public function next(array $company, string $docType, string $date): string
    {
        $fy = FinancialYear::of($date, (int) ($company['fy_start_month'] ?? 4));
        $companyId = (int) $company['id'];

        $this->db->execute(
            'INSERT IGNORE INTO number_series (company_id, doc_type, fy, next_no) VALUES (:c, :t, :fy, 1)',
            [':c' => $companyId, ':t' => $docType, ':fy' => $fy->label],
        );
        $next = $this->db->scalar(
            'SELECT next_no FROM number_series WHERE company_id = :c AND doc_type = :t AND fy = :fy FOR UPDATE',
            [':c' => $companyId, ':t' => $docType, ':fy' => $fy->label],
        );
        if ($next === null) {
            throw new LogicException('Number series row missing.');
        }
        $this->db->execute(
            'UPDATE number_series SET next_no = next_no + 1 WHERE company_id = :c AND doc_type = :t AND fy = :fy',
            [':c' => $companyId, ':t' => $docType, ':fy' => $fy->label],
        );

        return self::format($company, $docType, $fy->label, (int) $next);
    }

    /**
     * @param array<string, mixed> $company
     */
    public static function format(array $company, string $docType, string $fyLabel, int $no): string
    {
        $prefix = $docType === self::INVOICE ? (string) $company['invoice_prefix'] : $docType;
        return sprintf('%s/%s/%04d', $prefix, $fyLabel, $no);
    }
}
