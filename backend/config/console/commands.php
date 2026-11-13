<?php

declare(strict_types=1);

use App\Console;

return [
    'user:create' => Console\CreateUserCommand::class,
    'billing:generate' => Console\GenerateBillsCommand::class,
];
