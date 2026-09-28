<?php

namespace Wexample\SymfonyDataSync\Service;

use LogicException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;
use Wexample\SymfonyDataSync\Class\FieldMapping;
use Wexample\SymfonyDataSync\Class\RelationOutcome;
use Wexample\SymfonyDataSync\Class\SyncDefinition;
use Wexample\SymfonyDataSync\Class\SyncPlan;
use Wexample\SymfonyDataSync\Class\SyncRelation;
use Wexample\SymfonyDataSync\Class\SyncReport;
use Wexample\SymfonyDataSync\Enum\SyncOperation;
use Wexample\SymfonyDataSync\Enum\SyncOutcome;
use Wexample\SymfonyDataSync\Enum\SyncSide;
use Wexample\SymfonyDataSync\Event\PostOperationEvent;
use Wexample\SymfonyDataSync\Event\PreOperationEvent;
use Wexample\SymfonyDataSync\Helper\SyncValueHelper;
use Wexample\SymfonyDataSync\Interface\DisablableRemoteAdapterInterface;

/**
 * Runs a plan, relation by relation, synchronously. A failing relation is
 * recorded as an Error with its message and the run goes on: one refused
 * account must not stop the others, and nothing is swallowed silently.
 */
class SyncExecutor
{
    public function __construct(
        private readonly ?EventDispatcherInterface $dispatcher = null,
    ) {
    }

    /**
     * @param array<string, SyncDefinition> $definitions by key
     */
    public function execute(SyncPlan $plan, array $definitions, bool $dryRun = false): SyncReport
    {
        $outcomes = [];

        foreach ($plan->relations as $relation) {
            $definition = $definitions[$relation->definitionKey];

            if ($dryRun) {
                $outcomes[] = new RelationOutcome($relation, SyncOutcome::NothingToDo);

                continue;
            }

            if (! $relation->operation->isAction()) {
                $this->refreshAgreedHash($definition, $relation);
                $outcomes[] = new RelationOutcome($relation, SyncOutcome::NothingToDo);

                continue;
            }

            $this->dispatcher?->dispatch(new PreOperationEvent($definition, $relation));

            try {
                $outcome = $this->run($definition, $relation);
            } catch (Throwable $exception) {
                $outcome = new RelationOutcome($relation, SyncOutcome::Error, $exception->getMessage());
            }

            $this->dispatcher?->dispatch(new PostOperationEvent($definition, $outcome));
            $outcomes[] = $outcome;
        }

        return new SyncReport($outcomes, $dryRun);
    }

    private function run(SyncDefinition $definition, SyncRelation $relation): RelationOutcome
    {
        $local = $relation->local ? $definition->localStore->find($definition, $relation->local->id) : null;
        $needsLocal = in_array($relation->operation, [SyncOperation::LocalLink, SyncOperation::LocalUpdate, SyncOperation::RemoteUpdate, SyncOperation::RemoteCreate], true);
        $needsRemote = in_array($relation->operation, [SyncOperation::LocalLink, SyncOperation::LocalUpdate, SyncOperation::RemoteUpdate, SyncOperation::LocalCreate, SyncOperation::RemoteRemove, SyncOperation::RemoteDisable], true);

        if ($needsLocal && ! $local) {
            return new RelationOutcome($relation, SyncOutcome::Skipped, 'The local entity is gone.');
        }

        if ($needsRemote && ! $definition->adapter->get($relation->remote->id)) {
            return new RelationOutcome($relation, SyncOutcome::Skipped, 'The remote item is gone.');
        }

        switch ($relation->operation) {
            case SyncOperation::LocalLink:
                $definition->linkStore->link($definition, $local->id, $relation->remote->id);
                break;

            case SyncOperation::LocalUnlink:
                $definition->linkStore->unlink($definition, $relation->link->localId, $relation->link->remoteId);
                break;

            case SyncOperation::RemoteCreate:
                $created = $definition->adapter->create($this->values($definition, $local->fields, SyncSide::Remote));
                $definition->linkStore->link($definition, $local->id, $created->id, SyncValueHelper::hash($definition->fields, $local->fields, false));

                return new RelationOutcome($relation, SyncOutcome::Success, 'Created '.$created->id.'.');

            case SyncOperation::LocalCreate:
                $created = $definition->localStore->create($definition, $this->values($definition, $relation->remote->fields, SyncSide::Local));
                $definition->linkStore->link($definition, $created->id, $relation->remote->id, SyncValueHelper::hash($definition->fields, $relation->remote->fields, true));

                return new RelationOutcome($relation, SyncOutcome::Success, 'Created '.$created->id.'.');

            case SyncOperation::LocalUpdate:
            case SyncOperation::RemoteUpdate:
                $pushed = [];
                foreach ($relation->getDiffsFor(SyncSide::Remote) as $diff) {
                    $pushed[$diff->remoteField] = $diff->localValue;
                }

                $pulled = [];
                foreach ($relation->getDiffsFor(SyncSide::Local) as $diff) {
                    $pulled[$diff->localField] = $diff->remoteValue;
                }

                if ([] !== $pushed) {
                    $definition->adapter->update($relation->remote->id, $pushed);
                }

                if ([] !== $pulled) {
                    $local = $definition->localStore->update($definition, $local, $pulled);
                }

                // Both sides now hold these values: the next run compares against them.
                $definition->linkStore->touch($definition, $local->id, $relation->remote->id, SyncValueHelper::hash($definition->fields, $local->fields, false));
                break;

            case SyncOperation::RemoteRemove:
                $definition->adapter->remove($relation->remote->id);

                if ($relation->link) {
                    $definition->linkStore->unlink($definition, $relation->link->localId, $relation->link->remoteId);
                }
                break;

            case SyncOperation::RemoteDisable:
                if (! $definition->adapter instanceof DisablableRemoteAdapterInterface) {
                    throw new LogicException(sprintf('The "%s" adapter cannot disable items.', $definition->key));
                }

                $definition->adapter->disable($relation->remote->id);
                break;

            default:
                throw new LogicException(sprintf('No execution for "%s".', $relation->operation->value));
        }

        return new RelationOutcome($relation, SyncOutcome::Success);
    }

    /**
     * An up-to-date pair whose link has no hash yet, or an outdated one, gets
     * the agreed one: later changes can then be told apart side by side.
     */
    private function refreshAgreedHash(SyncDefinition $definition, SyncRelation $relation): void
    {
        if (SyncOperation::UpToDate !== $relation->operation || ! $relation->link || ! $relation->local) {
            return;
        }

        $hash = SyncValueHelper::hash($definition->fields, $relation->local->fields, false);

        if ($hash !== $relation->link->lastSyncedHash) {
            $definition->linkStore->touch($definition, $relation->link->localId, $relation->link->remoteId, $hash);
        }
    }

    /**
     * The mapped values of one side, keyed for the other: what a creation writes.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function values(SyncDefinition $definition, array $fields, SyncSide $target): array
    {
        $values = [];
        foreach ($definition->fields as $mapping) {
            /** @var FieldMapping $mapping */
            if (SyncSide::Remote === $target && $mapping->direction->pushes()) {
                $values[$mapping->remoteField] = $fields[$mapping->localField] ?? null;
            }

            if (SyncSide::Local === $target && $mapping->direction->pulls()) {
                $values[$mapping->localField] = $fields[$mapping->remoteField] ?? null;
            }
        }

        return $values;
    }
}
