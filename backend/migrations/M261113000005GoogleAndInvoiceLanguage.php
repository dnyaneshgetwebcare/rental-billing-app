<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

final class M261113000005GoogleAndInvoiceLanguage implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute(<<<'SQL'
            ALTER TABLE companies
                ADD COLUMN invoice_print_language ENUM('en', 'en_mr') NOT NULL DEFAULT 'en' AFTER invoice_terms
        SQL);
        $b->execute(<<<'SQL'
            ALTER TABLE users
                MODIFY password_hash VARCHAR(255) NULL,
                ADD COLUMN google_sub VARCHAR(64) NULL AFTER email,
                ADD UNIQUE KEY uq_users_google_sub (google_sub)
        SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute('ALTER TABLE users DROP INDEX uq_users_google_sub, DROP COLUMN google_sub, MODIFY password_hash VARCHAR(255) NOT NULL');
        $b->execute('ALTER TABLE companies DROP COLUMN invoice_print_language');
    }
}
