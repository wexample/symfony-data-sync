<?php

namespace Wexample\SymfonyDataSync\Command;

use InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Wexample\SymfonyDataSync\Enum\SyncOperation;
use Wexample\SymfonyDataSync\Service\SyncRunner;
use Wexample\SymfonyDataSync\WexampleSymfonyDataSyncBundle;
use Wexample\SymfonyHelpers\Command\AbstractBundleCommand;
use Wexample\SymfonyHelpers\Service\BundleService;

/**
 * Plans a sync and runs it, or only shows it with --dry-run. Fails when an
 * operation failed, so a cron notices.
 */
class RunCommand extends AbstractBundleCommand
{
    public const string FORMAT_JSON = 'json';

    public const string FORMAT_TABLE = 'table';

    public function __construct(
        BundleService $bundleService,
        private readonly SyncRunner $runner,
    ) {
        parent::__construct($bundleService);
    }

    public static function getBundleClassName(): string
    {
        return WexampleSymfonyDataSyncBundle::class;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Plans and runs a sync; every definition when none is named.')
            ->addArgument('definition', InputArgument::OPTIONAL, 'The definition key.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the plan, write nothing.')
            ->addOption('local-id', null, InputOption::VALUE_REQUIRED, 'Plan this local entity only, without listing the remote.')
            ->addOption('remote-id', null, InputOption::VALUE_REQUIRED, 'Keep the relations of this remote item only.')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Comma-separated operations to keep, by exact name (remote_create,local_link…).')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Show up-to-date relations too.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'table or json', self::FORMAT_TABLE);
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $only = $input->getOption('only');
        $report = $this->runner->run(
            definitionKey: $input->getArgument('definition'),
            dryRun: (bool) $input->getOption('dry-run'),
            localId: $input->getOption('local-id'),
            remoteId: $input->getOption('remote-id'),
            only: null === $only ? null : array_map(
                static fn (string $name): SyncOperation => SyncOperation::tryFrom(trim($name))
                    ?? throw new InvalidArgumentException(sprintf('Unknown operation "%s". Known: %s.', trim($name), implode(', ', array_column(SyncOperation::cases(), 'value')))),
                explode(',', $only)
            ),
            all: (bool) $input->getOption('all'),
        );

        if (self::FORMAT_JSON === $input->getOption('format')) {
            $output->writeln(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $io = new SymfonyStyle($input, $output);
            $io->table(['Definition', 'Operation', 'Local', 'Remote', 'Reason', 'Outcome'], $report->toRows());
            $io->text(sprintf(
                '%s%s',
                $report->dryRun ? 'Dry run. ' : '',
                implode(', ', array_map(static fn (string $operation, int $count): string => $count.' '.$operation, array_keys($report->countByOperation()), $report->countByOperation())) ?: 'Nothing to do.'
            ));
        }

        return $report->hasErrors() ? self::FAILURE : self::SUCCESS;
    }
}
