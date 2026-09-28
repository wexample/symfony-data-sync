<?php

namespace Wexample\SymfonyDataSync\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Wexample\SymfonyDataSync\Service\SyncLinker;
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
        private readonly SyncLinker $linker,
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
        $localId = $input->getArgument('localId');
        $remoteId = $input->getArgument('remoteId');

        $this->linker->link($input->getArgument('definition'), $localId, $remoteId);
        $output->writeln(sprintf('Linked %s to %s. The next run compares their fields.', $localId, $remoteId));

        return self::SUCCESS;
    }
}
