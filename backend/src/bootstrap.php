<?php

declare(strict_types=1);

use App\Environment;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Load backend/.env. Existing process environment variables take precedence (Docker, CI, server config).
// phpdotenv is a production dependency because GoDaddy shared hosting has no other way to set env vars.
if (empty($_ENV['APP_ENV']) && class_exists(\Dotenv\Dotenv::class)) {
    \Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

date_default_timezone_set(\App\Shared\Settings::get('APP_TIMEZONE', 'Asia/Kolkata') ?? 'Asia/Kolkata');

Environment::prepare();
