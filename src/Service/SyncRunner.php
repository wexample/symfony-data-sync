<?php

namespace Wexample\SymfonyDataSync\Service;

use InvalidArgumentException;
use Wexample\SymfonyDataSync\Class\SyncPlan;
use Wexample\SymfonyDataSync\Class\SyncRelation;
use Wexample\SymfonyDataSync\Class\SyncReport;
use Wexample\SymfonyDataSync\Enum\SyncOperation;

/**
 * Plans and runs definitions by key, with the filters the console and the
 * screens share.
 */
class SyncRunner
{
    public function __construct(
        private readonly SyncDefinitionRegistry $registry,
        private readonly SyncPlanner $planner,
        private readonly SyncExecutor $executor,
    ) {
    }

    /**
     * @param string|null $definitionKey null for every definition
     * @param string|null $localId plans this entity only, without listing the remote
     * @param SyncOperation[]|null $only keeps these operations only
     * @param bool $all keeps up-to-date pairs
     */
    public function run(
        ?string $definitionKey = null,
        bool $dryRun = true,
        ?string $localId = null,
        ?string $remoteId = null,
        ?array $only = null,
        bool $all = false,
    ): SyncReport {
        $definitions = null !== $definitionKey
            ? [$definitionKey => $this->registry->get($definitionKey)]
            : $this->registry->all();

        if (null !== $localId) {
            if (null === $definitionKey) {
                throw new InvalidArgumentException('A local id needs a definition.');
            }

            $definition = $definitions[$definitionKey];
            $local = $definition->localStore->find($definition, $localId) ?? throw new InvalidArgumentException(sprintf('No local entity "%s".', $localId));
            $plan = $this->planner->planOne($definition, $local);
        } else {
            $plan = $this->planner->planAll(array_values($definitions));
        }

        if (! $all) {
            $plan = $plan->withoutUpToDate();
        }

        if (null !== $only) {
            $plan = $plan->only($only);
        }

        if (null !== $remoteId) {
            $plan = new SyncPlan(array_values(array_filter(
                $plan->relations,
                static fn (SyncRelation $relation): bool => $remoteId === ($relation->remote?->id ?? $relation->link?->remoteId)
            )));
        }

        return $this->executor->execute($plan, $definitions, $dryRun);
    }
}
