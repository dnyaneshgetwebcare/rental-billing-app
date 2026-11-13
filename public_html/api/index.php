<?php

declare(strict_types=1);

/*
 * Web entry point for the Yii3 API. The application itself lives outside the web root:
 *   GoDaddy: ~/public_html/api/index.php  ->  ~/billing-api
 *   Docker:  /var/www/html/api/index.php   ->  /var/www/billing-api
 */

use App\Environment;
use Psr\Log\LogLevel;
use Yiisoft\ErrorHandler\ErrorHandler;
use Yiisoft\ErrorHandler\Renderer\JsonRenderer;
use Yiisoft\Log\Logger;
use Yiisoft\Log\StreamTarget;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

$root = getenv('BILLING_API_ROOT') ?: dirname(__DIR__, 2) . '/billing-api';

require_once $root . '/src/bootstrap.php';

$runner = new HttpApplicationRunner(
    rootPath: $root,
    debug: Environment::appDebug(),
    checkEvents: Environment::appDebug(),
    environment: Environment::appEnv(),
    temporaryErrorHandler: new ErrorHandler(
        new Logger([
            (new StreamTarget())->setLevels([LogLevel::EMERGENCY, LogLevel::ERROR, LogLevel::WARNING]),
        ]),
        new JsonRenderer(),
    ),
);
$runner->run();
