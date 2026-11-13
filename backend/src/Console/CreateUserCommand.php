<?php

declare(strict_types=1);

namespace App\Console;

use App\Auth\AuthService;
use App\Shared\ApiException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

#[AsCommand(
    name: 'user:create',
    description: 'Create a login. The first user then creates companies from the app.',
)]
final class CreateUserCommand extends Command
{
    public function __construct(private readonly AuthService $auth)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED)
            ->addArgument('name', InputArgument::REQUIRED)
            ->addArgument('password', InputArgument::REQUIRED, 'At least 8 characters');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $id = $this->auth->createUser(
                (string) $input->getArgument('name'),
                (string) $input->getArgument('email'),
                (string) $input->getArgument('password'),
            );
        } catch (ApiException $e) {
            $output->writeln('<error>' . $e->getMessage() . ' ' . json_encode($e->fields) . '</error>');
            return ExitCode::DATAERR;
        }
        $output->writeln("Created user #$id");
        return ExitCode::OK;
    }
}
