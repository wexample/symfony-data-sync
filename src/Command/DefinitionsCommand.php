<?php

namespace Wexample\SymfonyDataSync\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Wexample\SymfonyDataSync\Class\SyncDefinition;
use Wexample\SymfonyDataSync\Interface\MatchRuleInterface;
use Wexample\SymfonyDataSync\Service\SyncDefinitionRegistry;
use Wexample\SymfonyDataSync\WexampleSymfonyDataSyncBundle;
use Wexample\SymfonyHelpers\Command\AbstractBundleCommand;
use Wexample\SymfonyHelpers\Service\BundleService;

/**
 * Lists the declared definitions and the policies they run with.
 */
class DefinitionsCommand extends AbstractBundleCommand
{
    public function __construct(
        BundleService $bundleService,
        private readonly SyncDefinitionRegistry $registry,
    ) {
        parent::__construct($bundleService);
    }

    public static function getBundleClassName(): string
    {
        return WexampleSymfonyDataSyncBundle::class;
    }

    protected function configure(): void
    {
        $this->setDescription('Lists the sync definitions.');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        (new SymfonyStyle($input, $output))->table(
            ['Definition', 'Local', 'Adapter', 'Match', 'Orphans (remote / local)', 'Excluded local', 'Conflict'],
            array_map(static fn (SyncDefinition $definition): array => [
                $definition->key,
                $definition->localClass,
                $definition->adapter::class,
                implode("\n", array_map(static fn (MatchRuleInterface $rule): string => $rule->describe(), $definition->matchRules)),
                $definition->orphanRemote->value.' / '.$definition->orphanLocal->value,
                $definition->excludedLocal->value,
                $definition->conflict->value,
            ], array_values($this->registry->all()))
        );

        return self::SUCCESS;
    }
}
