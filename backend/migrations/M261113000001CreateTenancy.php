<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

final class M261113000001CreateTenancy implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute(<<<'SQL'
            CREATE TABLE companies (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                legal_name VARCHAR(200) NULL,
                gstin VARCHAR(15) NULL,
                pan VARCHAR(10) NULL,
                state_code CHAR(2) NOT NULL,
                address TEXT NULL,
                city VARCHAR(100) NULL,
                pincode VARCHAR(10) NULL,
                phone VARCHAR(20) NULL,
                email VARCHAR(150) NULL,
                fy_start_month TINYINT UNSIGNED NOT NULL DEFAULT 4,
                invoice_prefix VARCHAR(5) NOT NULL,
                bank_details TEXT NULL,
                invoice_terms TEXT NULL,
                logo_path VARCHAR(255) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $b->execute(<<<'SQL'
            CREATE TABLE users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                email VARCHAR(150) NOT NULL,
                phone VARCHAR(20) NULL,
                password_hash VARCHAR(255) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                last_login_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_users_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $b->execute(<<<'SQL'
            CREATE TABLE company_users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                role ENUM('admin','accountant','staff') NOT NULL DEFAULT 'staff',
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_company_user (company_id, user_id),
                KEY idx_company_users_user (user_id),
                CONSTRAINT fk_cu_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
                CONSTRAINT fk_cu_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $b->execute(<<<'SQL'
            CREATE TABLE refresh_tokens (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                revoked_at DATETIME NULL,
                user_agent VARCHAR(255) NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_refresh_token (token_hash),
                KEY idx_refresh_user (user_id),
                CONSTRAINT fk_rt_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute('DROP TABLE IF EXISTS refresh_tokens');
        $b->execute('DROP TABLE IF EXISTS company_users');
        $b->execute('DROP TABLE IF EXISTS users');
        $b->execute('DROP TABLE IF EXISTS companies');
    }
}
