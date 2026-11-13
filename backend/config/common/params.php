<?php

declare(strict_types=1);

return [
    'application' => require __DIR__ . '/application.php',

    'yiisoft/aliases' => [
        'aliases' => require __DIR__ . '/aliases.php',
    ],

    'yiisoft/db-migration' => [
        'newMigrationNamespace' => '',
        'newMigrationPath' => dirname(__DIR__, 2) . '/migrations',
        'sourceNamespaces' => [],
        'sourcePaths' => [dirname(__DIR__, 2) . '/migrations'],
        'databases' => [],
    ],
];
