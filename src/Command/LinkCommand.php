<?php

namespace Wexample\SymfonyDataSync\Command;

use InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Wexample\SymfonyDataSync\Service\SyncDefinitionRegistry;
use Wexample\SymfonyDataSync\WexampleSymfonyDataSyncBundle;
use Wexample\SymfonyHelpers\Command\AbstractBundleCommand;
use Wexample\SymfonyHelpers\Service\BundleService;

/**
 * Links a local entity and a remote item by hand: how a candidate or a
 * conflict the planner reported gets resolved.
 */
class LinkCommand extends AbstractBundleCommand
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
        $this
            ->setDescription('Links a local entity to a remote item by hand.')
            ->addArgument('definition', InputArgument::REQUIRED)
            ->addArgument('localId', InputArgument::REQUIRED)
            ->addArgument('remoteId', InputArgument::REQUIRED);
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $definition = $this->registry->get($input->getArgument('definition'));
        $localId = $input->getArgument('localId');
        $remoteId = $input->getArgument('remoteId');

        $definition->localStore->find($definition, $localId) ?? throw new InvalidArgumentException(sprintf('No local entity "%s".', $localId));
        $definition->adapter->get($remoteId) ?? throw new InvalidArgumentException(sprintf('No remote item "%s".', $remoteId));

        if ($link = $definition->linkStore->findByLocal($definition, $localId)) {
            throw new InvalidArgumentException(sprintf('"%s" is already linked to "%s": unlink it first.', $localId, $link->remoteId));
        }

        if ($link = $definition->linkStore->findByRemote($definition, $remoteId)) {
            throw new InvalidArgumentException(sprintf('"%s" is already linked to "%s": unlink it first.', $remoteId, $link->localId));
        }

        $definition->linkStore->link($definition, $localId, $remoteId);
        $output->writeln(sprintf('Linked %s to %s. The next run compares their fields.', $localId, $remoteId));

        return self::SUCCESS;
    }
}
