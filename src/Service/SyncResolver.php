<?php

namespace Wexample\SymfonyDataSync\Service;

use InvalidArgumentException;
use Wexample\SymfonyDataSync\Class\FieldDiff;
use Wexample\SymfonyDataSync\Class\SyncPlan;
use Wexample\SymfonyDataSync\Class\SyncRelation;
use Wexample\SymfonyDataSync\Class\SyncReport;
use Wexample\SymfonyDataSync\Enum\SyncOperation;
use Wexample\SymfonyDataSync\Enum\SyncSide;

/**
 * Settles the field conflict of a linked pair the way a human chose: the kept
 * side's values are written on the other side, then both agree and the link
 * records it.
 */
class SyncResolver
{
    public function __construct(
        private readonly SyncDefinitionRegistry $registry,
        private readonly SyncPlanner $planner,
        private readonly SyncExecutor $executor,
    ) {
    }

    /**
     * @param SyncSide $kept Local keeps the application's values, Remote the remote's
     */
    public function resolve(string $definitionKey, string $localId, SyncSide $kept): SyncReport
    {
        $definition = $this->registry->get($definitionKey);
        $local = $definition->localStore->find($definition, $localId) ?? throw new InvalidArgumentException(sprintf('No local entity "%s".', $localId));

        $conflict = null;
        foreach ($this->planner->planOne($definition, $local)->relations as $relation) {
            if (SyncOperation::Conflict === $relation->operation && $relation->remote) {
                $conflict = $relation;
            }
        }

        if (! $conflict) {
            throw new InvalidArgumentException(sprintf('"%s" has no field conflict to resolve.', $localId));
        }

        $written = SyncSide::Local === $kept ? SyncSide::Remote : SyncSide::Local;
        $diffs = array_map(
            static fn (FieldDiff $diff): FieldDiff => SyncSide::None === $diff->target
                ? new FieldDiff($diff->localField, $diff->remoteField, $diff->localValue, $diff->remoteValue, $written)
                : $diff,
            $conflict->diffs
        );

        $hasLocalWrite = [] !== array_filter($diffs, static fn (FieldDiff $diff): bool => SyncSide::Local === $diff->target);

        return $this->executor->execute(new SyncPlan([new SyncRelation(
            $definitionKey,
            $hasLocalWrite ? SyncOperation::LocalUpdate : SyncOperation::RemoteUpdate,
            sprintf('Conflict resolved by hand: %s values kept.', $kept->value),
            $conflict->local,
            $conflict->remote,
            $conflict->link,
            $diffs,
        )]), [$definitionKey => $definition]);
    }
}
