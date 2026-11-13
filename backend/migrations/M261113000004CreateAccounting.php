<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

final class M261113000004CreateAccounting implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $b->execute(<<<SQL
            CREATE TABLE vouchers (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                number VARCHAR(20) NOT NULL,
                voucher_type ENUM('sales','credit_note','receipt','purchase','expense','payment','journal','reversal') NOT NULL,
                voucher_date DATE NOT NULL,
                source_type VARCHAR(30) NULL,
                source_id INT UNSIGNED NULL,
                narration VARCHAR(255) NULL,
                is_reversed TINYINT(1) NOT NULL DEFAULT 0,
                reverses_voucher_id INT UNSIGNED NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_voucher_number (company_id, number),
                KEY idx_vouchers_date (company_id, voucher_date),
                KEY idx_vouchers_source (source_type, source_id),
                CONSTRAINT fk_vouchers_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
            ) $opts
        SQL);

        $b->execute(<<<SQL
            CREATE TABLE voucher_lines (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                voucher_id INT UNSIGNED NOT NULL,
                account_id INT UNSIGNED NOT NULL,
                party_type ENUM('tenant','vendor') NULL,
                party_id INT UNSIGNED NULL,
                debit DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                credit DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                narration VARCHAR(255) NULL,
                KEY idx_vl_voucher (voucher_id),
                KEY idx_vl_account (company_id, account_id),
                KEY idx_vl_party (company_id, party_type, party_id),
                CONSTRAINT fk_vl_voucher FOREIGN KEY (voucher_id) REFERENCES vouchers (id) ON DELETE CASCADE,
                CONSTRAINT fk_vl_account FOREIGN KEY (account_id) REFERENCES accounts (id)
            ) $opts
        SQL);

        $b->execute(<<<SQL
            CREATE TABLE purchases (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                vendor_id INT UNSIGNED NOT NULL,
                bill_no VARCHAR(30) NOT NULL,
                bill_date DATE NOT NULL,
                place_of_supply CHAR(2) NOT NULL,
                is_inter_state TINYINT(1) NOT NULL DEFAULT 0,
                taxable_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                cgst DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                sgst DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                igst DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                round_off DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                status ENUM('unpaid','partial','paid','cancelled') NOT NULL DEFAULT 'unpaid',
                notes TEXT NULL,
                voucher_id INT UNSIGNED NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_purchase_bill (company_id, vendor_id, bill_no),
                KEY idx_purchases_date (company_id, bill_date),
                CONSTRAINT fk_purchases_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
                CONSTRAINT fk_purchases_vendor FOREIGN KEY (vendor_id) REFERENCES vendors (id)
            ) $opts
        SQL);

        $b->execute(<<<SQL
            CREATE TABLE purchase_lines (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                purchase_id INT UNSIGNED NOT NULL,
                account_id INT UNSIGNED NOT NULL,
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
                sort SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                KEY idx_purchase_lines_purchase (purchase_id),
                CONSTRAINT fk_pl_purchase FOREIGN KEY (purchase_id) REFERENCES purchases (id) ON DELETE CASCADE,
                CONSTRAINT fk_pl_account FOREIGN KEY (account_id) REFERENCES accounts (id)
            ) $opts
        SQL);

        $b->execute(<<<SQL
            CREATE TABLE expenses (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                expense_date DATE NOT NULL,
                account_id INT UNSIGNED NOT NULL,
                paid_from_account_id INT UNSIGNED NOT NULL,
                vendor_id INT UNSIGNED NULL,
                description VARCHAR(255) NOT NULL,
                amount DECIMAL(14,2) NOT NULL,
                gst_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                is_inter_state TINYINT(1) NOT NULL DEFAULT 0,
                cgst DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                sgst DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                igst DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                total DECIMAL(14,2) NOT NULL,
                payment_mode ENUM('cash','bank','upi','cheque') NOT NULL DEFAULT 'cash',
                reference VARCHAR(100) NULL,
                status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
                voucher_id INT UNSIGNED NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                KEY idx_expenses_date (company_id, expense_date),
                CONSTRAINT fk_expenses_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
                CONSTRAINT fk_expenses_account FOREIGN KEY (account_id) REFERENCES accounts (id)
            ) $opts
        SQL);

        $b->execute(<<<SQL
            CREATE TABLE vendor_payments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                vendor_id INT UNSIGNED NOT NULL,
                purchase_id INT UNSIGNED NULL,
                payment_date DATE NOT NULL,
                amount DECIMAL(14,2) NOT NULL,
                account_id INT UNSIGNED NOT NULL,
                mode ENUM('cash','bank','upi','cheque') NOT NULL DEFAULT 'bank',
                reference VARCHAR(100) NULL,
                status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
                voucher_id INT UNSIGNED NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                KEY idx_vp_vendor (company_id, vendor_id),
                CONSTRAINT fk_vp_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
                CONSTRAINT fk_vp_vendor FOREIGN KEY (vendor_id) REFERENCES vendors (id)
            ) $opts
        SQL);

        $b->execute(<<<SQL
            CREATE TABLE attachments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                owner_type VARCHAR(30) NOT NULL,
                owner_id INT UNSIGNED NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                path VARCHAR(255) NOT NULL,
                thumb_path VARCHAR(255) NULL,
                medium_path VARCHAR(255) NULL,
                mime VARCHAR(100) NOT NULL,
                size INT UNSIGNED NOT NULL,
                width SMALLINT UNSIGNED NULL,
                height SMALLINT UNSIGNED NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                KEY idx_attachments_owner (company_id, owner_type, owner_id),
                CONSTRAINT fk_attachments_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
            ) $opts
        SQL);

        $b->execute(<<<SQL
            CREATE TABLE audit_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NULL,
                user_id INT UNSIGNED NULL,
                action VARCHAR(30) NOT NULL,
                entity VARCHAR(50) NOT NULL,
                entity_id INT UNSIGNED NULL,
                data JSON NULL,
                ip VARCHAR(45) NULL,
                created_at DATETIME NOT NULL,
                KEY idx_audit_company (company_id, created_at),
                KEY idx_audit_entity (entity, entity_id)
            ) $opts
        SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        foreach (['audit_logs', 'attachments', 'vendor_payments', 'expenses', 'purchase_lines', 'purchases', 'voucher_lines', 'vouchers'] as $t) {
            $b->execute("DROP TABLE IF EXISTS $t");
        }
    }
}
