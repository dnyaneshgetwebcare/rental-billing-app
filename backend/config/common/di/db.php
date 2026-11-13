<?php

declare(strict_types=1);

use App\Shared\Settings;
use Psr\SimpleCache\CacheInterface;
use Yiisoft\Cache\File\FileCache;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Mysql\Connection;
use Yiisoft\Db\Mysql\Driver;
use Yiisoft\Db\Mysql\Dsn;

/** @var array $params */

return [
    CacheInterface::class => static fn() => new FileCache(dirname(__DIR__, 3) . '/runtime/cache'),

    ConnectionInterface::class => static function (CacheInterface $cache): ConnectionInterface {
        $dsn = new Dsn(
            'mysql',
            Settings::get('DB_HOST', '127.0.0.1') ?? '127.0.0.1',
            Settings::require('DB_NAME'),
            Settings::get('DB_PORT', '3306') ?? '3306',
            ['charset' => 'utf8mb4'],
        );
        $driver = new Driver(
            (string) $dsn,
            Settings::require('DB_USER'),
            Settings::get('DB_PASSWORD', '') ?? '',
            [PDO::ATTR_EMULATE_PREPARES => true],
        );
        $connection = new Connection($driver, new SchemaCache($cache));
        // Store DATETIME values in IST; GoDaddy's MySQL server time zone is often US-based.
        $connection->createCommand("SET time_zone = '+05:30'")->execute();
        return $connection;
    },
];
