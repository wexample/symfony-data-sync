<?php

namespace Wexample\SymfonyDataSync\Command;

use InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Wexample\SymfonyDataSync\Class\SyncPlan;
use Wexample\SymfonyDataSync\Class\SyncRelation;
use Wexample\SymfonyDataSync\Enum\SyncOperation;
use Wexample\SymfonyDataSync\Service\SyncDefinitionRegistry;
use Wexample\SymfonyDataSync\Service\SyncExecutor;
use Wexample\SymfonyDataSync\Service\SyncPlanner;
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
        private readonly SyncDefinitionRegistry $registry,
        private readonly SyncPlanner $planner,
        private readonly SyncExecutor $executor,
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
        $key = $input->getArgument('definition');
        $definitions = null !== $key ? [$key => $this->registry->get($key)] : $this->registry->all();
        $localId = $input->getOption('local-id');

        if (null !== $localId) {
            if (null === $key) {
                throw new InvalidArgumentException('--local-id needs a definition.');
            }

            $definition = $definitions[$key];
            $local = $definition->localStore->find($definition, $localId) ?? throw new InvalidArgumentException(sprintf('No local entity "%s".', $localId));
            $plan = $this->planner->planOne($definition, $local);
        } else {
            $plan = $this->planner->planAll(array_values($definitions));
        }

        $plan = $this->filter($plan, $input);
        $report = $this->executor->execute($plan, $definitions, (bool) $input->getOption('dry-run'));

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

    private function filter(SyncPlan $plan, InputInterface $input): SyncPlan
    {
        if (! $input->getOption('all')) {
            $plan = $plan->withoutUpToDate();
        }

        if (null !== $only = $input->getOption('only')) {
            $plan = $plan->only(array_map(
                static fn (string $name): SyncOperation => SyncOperation::tryFrom(trim($name))
                    ?? throw new InvalidArgumentException(sprintf('Unknown operation "%s". Known: %s.', trim($name), implode(', ', array_column(SyncOperation::cases(), 'value')))),
                explode(',', $only)
            ));
        }

        if (null !== $remoteId = $input->getOption('remote-id')) {
            $plan = new SyncPlan(array_values(array_filter(
                $plan->relations,
                static fn (SyncRelation $relation): bool => $remoteId === ($relation->remote?->id ?? $relation->link?->remoteId)
            )));
        }

        return $plan;
    }
}
