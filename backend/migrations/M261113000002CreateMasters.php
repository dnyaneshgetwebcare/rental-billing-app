<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

final class M261113000002CreateMasters implements RevertibleMigrationInterface
{
    private const AUDIT = <<<'SQL'
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    SQL;

    public function up(MigrationBuilder $b): void
    {
        $audit = self::AUDIT;
        $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        foreach (['tenants', 'vendors'] as $table) {
            $b->execute(<<<SQL
                CREATE TABLE $table (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    company_id INT UNSIGNED NOT NULL,
                    name VARCHAR(200) NOT NULL,
                    contact_person VARCHAR(150) NULL,
                    gstin VARCHAR(15) NULL,
                    pan VARCHAR(10) NULL,
                    state_code CHAR(2) NOT NULL,
                    phone VARCHAR(20) NULL,
                    whatsapp VARCHAR(20) NULL,
                    email VARCHAR(150) NULL,
                    address TEXT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    $audit,
                    KEY idx_{$table}_company (company_id, name),
                    CONSTRAINT fk_{$table}_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
                ) $opts
            SQL);
        }

        $b->execute(<<<SQL
            CREATE TABLE warehouses (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                name VARCHAR(150) NOT NULL,
                address TEXT NULL,
                city VARCHAR(100) NULL,
                state_code CHAR(2) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                $audit,
                KEY idx_warehouses_company (company_id),
                CONSTRAINT fk_warehouses_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
            ) $opts
        SQL);

        $b->execute(<<<SQL
            CREATE TABLE units (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                warehouse_id INT UNSIGNED NOT NULL,
                code VARCHAR(30) NOT NULL,
                area_sqft DECIMAL(12,2) NULL,
                description VARCHAR(255) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                $audit,
                UNIQUE KEY uq_units_code (company_id, warehouse_id, code),
                CONSTRAINT fk_units_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
                CONSTRAINT fk_units_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses (id)
            ) $opts
        SQL);

        $b->execute(<<<SQL
            CREATE TABLE tax_rates (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                name VARCHAR(100) NOT NULL,
                rate DECIMAL(5,2) NOT NULL,
                sac VARCHAR(10) NULL,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                $audit,
                KEY idx_tax_rates_company (company_id),
                CONSTRAINT fk_tax_rates_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
            ) $opts
        SQL);

        $b->execute(<<<SQL
            CREATE TABLE accounts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                code VARCHAR(20) NOT NULL,
                name VARCHAR(150) NOT NULL,
                type ENUM('asset','liability','equity','income','expense') NOT NULL,
                system_key VARCHAR(30) NULL,
                is_cash_bank TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                $audit,
                UNIQUE KEY uq_accounts_code (company_id, code),
                UNIQUE KEY uq_accounts_system (company_id, system_key),
                CONSTRAINT fk_accounts_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
            ) $opts
        SQL);

        $b->execute(<<<SQL
            CREATE TABLE agreements (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                tenant_id INT UNSIGNED NOT NULL,
                unit_id INT UNSIGNED NOT NULL,
                agreement_no VARCHAR(50) NULL,
                start_date DATE NOT NULL,
                end_date DATE NULL,
                rent_amount DECIMAL(14,2) NOT NULL,
                gst_rate DECIMAL(5,2) NOT NULL DEFAULT 18.00,
                sac VARCHAR(10) NOT NULL DEFAULT '997212',
                billing_day TINYINT UNSIGNED NOT NULL DEFAULT 1,
                due_days SMALLINT UNSIGNED NOT NULL DEFAULT 7,
                escalation_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                escalation_months SMALLINT UNSIGNED NOT NULL DEFAULT 12,
                deposit_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                auto_bill TINYINT(1) NOT NULL DEFAULT 1,
                status ENUM('active','ended') NOT NULL DEFAULT 'active',
                notes TEXT NULL,
                $audit,
                KEY idx_agreements_company (company_id, status),
                KEY idx_agreements_tenant (tenant_id),
                CONSTRAINT fk_agreements_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
                CONSTRAINT fk_agreements_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
                CONSTRAINT fk_agreements_unit FOREIGN KEY (unit_id) REFERENCES units (id)
            ) $opts
        SQL);

        $b->execute(<<<SQL
            CREATE TABLE agreement_charges (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                agreement_id INT UNSIGNED NOT NULL,
                description VARCHAR(150) NOT NULL,
                amount DECIMAL(14,2) NOT NULL,
                gst_rate DECIMAL(5,2) NOT NULL DEFAULT 18.00,
                sac VARCHAR(10) NOT NULL DEFAULT '997212',
                KEY idx_charges_agreement (agreement_id),
                CONSTRAINT fk_charges_agreement FOREIGN KEY (agreement_id) REFERENCES agreements (id) ON DELETE CASCADE
            ) $opts
        SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        foreach (['agreement_charges', 'agreements', 'accounts', 'tax_rates', 'units', 'warehouses', 'vendors', 'tenants'] as $t) {
            $b->execute("DROP TABLE IF EXISTS $t");
        }
    }
}
