<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

final class M261113000003CreateBilling implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $taxCols = <<<'SQL'
            place_of_supply CHAR(2) NOT NULL,
            is_inter_state TINYINT(1) NOT NULL DEFAULT 0,
            taxable_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            cgst DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            sgst DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            igst DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            round_off DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            total DECIMAL(14,2) NOT NULL DEFAULT 0.00
        SQL;
        $lineCols = <<<'SQL'
            account_id INT UNSIGNED NULL,
            description VARCHAR(255) NOT NULL,
            sac VARCHAR(10) NULL,
            qty DECIMAL(12,3) NOT NULL DEFAULT 1.000,
            rate DECIMAL(14,2) NOT NULL,
            gst_rate DECIMAL(5,2) NOT NULL,
            taxable_amount DECIMAL(14,2) NOT NULL,
            cgst DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            sgst DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            igst DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            total DECIMAL(14,2) NOT NULL,
            sort SMALLINT UNSIGNED NOT NULL DEFAULT 0
        SQL;

        $b->execute(<<<SQL
            CREATE TABLE number_series (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                doc_type VARCHAR(10) NOT NULL,
                fy VARCHAR(5) NOT NULL,
                next_no INT UNSIGNED NOT NULL DEFAULT 1,
                UNIQUE KEY uq_series (company_id, doc_type, fy),
                CONSTRAINT fk_series_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
            ) $opts
        SQL);

        // period is the billing-period key (YYYY-MM) used to block double billing; it is cleared when an
        // invoice is cancelled so the period can be billed again. period_label keeps the original value.
        $b->execute(<<<SQL
            CREATE TABLE invoices (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                tenant_id INT UNSIGNED NOT NULL,
                agreement_id INT UNSIGNED NULL,
                period CHAR(7) NULL,
                period_label CHAR(7) NULL,
                number VARCHAR(20) NOT NULL,
                invoice_date DATE NOT NULL,
                due_date DATE NULL,
                $taxCols,
                paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                credited_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                status ENUM('unpaid','partial','paid','cancelled') NOT NULL DEFAULT 'unpaid',
                source ENUM('manual','auto') NOT NULL DEFAULT 'manual',
                notes TEXT NULL,
                voucher_id INT UNSIGNED NULL,
                cancelled_at DATETIME NULL,
                cancel_reason VARCHAR(255) NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_invoice_number (company_id, number),
                UNIQUE KEY uq_invoice_period (agreement_id, period),
                KEY idx_invoices_company_date (company_id, invoice_date),
                KEY idx_invoices_tenant (company_id, tenant_id, status),
                CONSTRAINT fk_invoices_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
                CONSTRAINT fk_invoices_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
                CONSTRAINT fk_invoices_agreement FOREIGN KEY (agreement_id) REFERENCES agreements (id)
            ) $opts
        SQL);

        $b->execute(<<<SQL
            CREATE TABLE invoice_lines (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                invoice_id INT UNSIGNED NOT NULL,
                $lineCols,
                KEY idx_invoice_lines_invoice (invoice_id),
                CONSTRAINT fk_invoice_lines_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE
            ) $opts
        SQL);

        $b->execute(<<<SQL
            CREATE TABLE credit_notes (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                invoice_id INT UNSIGNED NOT NULL,
                tenant_id INT UNSIGNED NOT NULL,
                number VARCHAR(20) NOT NULL,
                note_date DATE NOT NULL,
                reason VARCHAR(255) NOT NULL,
                $taxCols,
                status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
                voucher_id INT UNSIGNED NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_credit_note_number (company_id, number),
                KEY idx_credit_notes_invoice (invoice_id),
                CONSTRAINT fk_cn_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
                CONSTRAINT fk_cn_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id)
            ) $opts
        SQL);

        $b->execute(<<<SQL
            CREATE TABLE credit_note_lines (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                credit_note_id INT UNSIGNED NOT NULL,
                $lineCols,
                KEY idx_cn_lines_note (credit_note_id),
                CONSTRAINT fk_cn_lines_note FOREIGN KEY (credit_note_id) REFERENCES credit_notes (id) ON DELETE CASCADE
            ) $opts
        SQL);

        $b->execute(<<<SQL
            CREATE TABLE receipts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                tenant_id INT UNSIGNED NOT NULL,
                number VARCHAR(20) NOT NULL,
                receipt_date DATE NOT NULL,
                amount DECIMAL(14,2) NOT NULL,
                unallocated_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                mode ENUM('cash','bank','upi','cheque') NOT NULL DEFAULT 'bank',
                account_id INT UNSIGNED NOT NULL,
                reference VARCHAR(100) NULL,
                notes TEXT NULL,
                status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
                voucher_id INT UNSIGNED NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_receipt_number (company_id, number),
                KEY idx_receipts_tenant (company_id, tenant_id),
                CONSTRAINT fk_receipts_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
                CONSTRAINT fk_receipts_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
            ) $opts
        SQL);

        $b->execute(<<<SQL
            CREATE TABLE receipt_allocations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                receipt_id INT UNSIGNED NOT NULL,
                invoice_id INT UNSIGNED NOT NULL,
                amount DECIMAL(14,2) NOT NULL,
                KEY idx_alloc_receipt (receipt_id),
                KEY idx_alloc_invoice (invoice_id),
                CONSTRAINT fk_alloc_receipt FOREIGN KEY (receipt_id) REFERENCES receipts (id) ON DELETE CASCADE,
                CONSTRAINT fk_alloc_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id)
            ) $opts
        SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        foreach (['receipt_allocations', 'receipts', 'credit_note_lines', 'credit_notes', 'invoice_lines', 'invoices', 'number_series'] as $t) {
            $b->execute("DROP TABLE IF EXISTS $t");
        }
    }
}
