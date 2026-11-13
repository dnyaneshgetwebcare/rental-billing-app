<?php

header('Content-Type: text/plain; charset=utf-8');

$expected = [
    'bcmath',
    'curl',
    'exif',
    'fileinfo',
    'gd',
    'gettext',
    'intl',
    'mbstring',
    'mysqli',
    'openssl',
    'pdo_mysql',
    'soap',
    'sockets',
    'zip',
    'Zend OPcache',
];

echo 'PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ')' . PHP_EOL;
echo 'timezone: ' . date_default_timezone_get() . PHP_EOL;
echo 'memory_limit: ' . ini_get('memory_limit') . PHP_EOL;
echo 'upload_max_filesize: ' . ini_get('upload_max_filesize') . PHP_EOL;
echo PHP_EOL . 'GoDaddy-style extensions:' . PHP_EOL;

foreach ($expected as $name) {
    $loaded = extension_loaded($name) ? 'yes' : 'NO';
    echo sprintf("  %-16s %s%s", $name, $loaded, PHP_EOL);
}
