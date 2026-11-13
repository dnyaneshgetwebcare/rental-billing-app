<?php

declare(strict_types=1);

namespace App\Console;

use App\Agreement\AutoBillingService;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

/**
 * Daily cPanel cron:  /usr/local/bin/php ~/billing-api/yii billing:generate
 */
#[AsCommand(
    name: 'billing:generate',
    description: 'Create monthly rent invoices for agreements whose billing day has arrived.',
)]
final class GenerateBillsCommand extends Command
{
    public function __construct(private readonly AutoBillingService $billing)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Run as if today were this date (YYYY-MM-DD)')
            ->addOption('company', null, InputOption::VALUE_REQUIRED, 'Only this company id')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be billed without saving');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $date = (string) ($input->getOption('date') ?? date('Y-m-d'));
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($d === false || $d->format('Y-m-d') !== $date) {
            $output->writeln('<error>--date must be YYYY-MM-DD</error>');
            return ExitCode::USAGE;
        }
        $company = $input->getOption('company');
        $result = $this->billing->run($date, $company === null ? null : (int) $company, (bool) $input->getOption('dry-run'));

        foreach ($result['created'] as $row) {
            $output->writeln(sprintf(
                '%s company agreement #%d %s (%s) period %s taxable %s%s',
                isset($row['dry_run']) ? 'WOULD BILL' : 'BILLED',
                $row['agreement_id'],
                $row['tenant_name'],
                $row['unit_code'],
                $row['period'],
                $row['taxable_amount'],
                isset($row['invoice_id']) ? ' -> invoice #' . $row['invoice_id'] : '',
            ));
        }
        foreach ($result['errors'] as $error) {
            $output->writeln(sprintf(
                '<error>FAILED company #%d agreement #%d period %s: %s</error>',
                $error['company_id'],
                $error['agreement_id'],
                $error['period'],
                $error['message'],
            ));
        }
        $output->writeln(sprintf(
            '%s: %d created, %d already billed, %d errors',
            $date,
            count($result['created']),
            $result['skipped'],
            count($result['errors']),
        ));
        return $result['errors'] === [] ? ExitCode::OK : ExitCode::SOFTWARE;
    }
}
