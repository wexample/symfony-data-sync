<?php

namespace Wexample\SymfonyDataSync\Service;

use Wexample\SymfonyDataSync\Class\FieldDiff;
use Wexample\SymfonyDataSync\Class\LinkRecord;
use Wexample\SymfonyDataSync\Class\LocalItem;
use Wexample\SymfonyDataSync\Class\MatchRule\ExactFieldRule;
use Wexample\SymfonyDataSync\Class\RemoteItem;
use Wexample\SymfonyDataSync\Class\SyncDefinition;
use Wexample\SymfonyDataSync\Class\SyncPlan;
use Wexample\SymfonyDataSync\Class\SyncRelation;
use Wexample\SymfonyDataSync\Enum\ConflictPolicy;
use Wexample\SymfonyDataSync\Enum\ExcludedLocalPolicy;
use Wexample\SymfonyDataSync\Enum\FieldDirection;
use Wexample\SymfonyDataSync\Enum\OrphanLocalPolicy;
use Wexample\SymfonyDataSync\Enum\OrphanRemotePolicy;
use Wexample\SymfonyDataSync\Enum\SyncOperation;
use Wexample\SymfonyDataSync\Enum\SyncSide;
use Wexample\SymfonyDataSync\Helper\SyncValueHelper;
use Wexample\SymfonyDataSync\Interface\DisablableRemoteAdapterInterface;
use Wexample\SymfonyDataSync\Interface\SearchableRemoteAdapterInterface;

/**
 * Decides, without writing anything, what a run would do.
 *
 * Pass 1 walks the stored links: gone or duplicated ones are unlinked, valid
 * pairs are compared field by field. Pass 2 hands what is left unlinked to the
 * matcher, then to the orphan policies. An item unlinked in this run is not
 * matched again in the same run: the next run links it, one change at a time.
 */
class SyncPlanner
{
    public function __construct(
        private readonly Matcher $matcher,
    ) {
    }

    /**
     * Plans several definitions at once. When two of them would write the same
     * local entity, or create two local entities for the same identity, the
     * first wins and the other waits for the next run, where it finds the
     * result of the first.
     *
     * @param SyncDefinition[] $definitions
     */
    public function planAll(array $definitions): SyncPlan
    {
        $relations = [];
        foreach ($definitions as $definition) {
            $relations = [...$relations, ...$this->plan($definition)->relations];
        }

        return new SyncPlan($this->postponeConcurrentLocalWrites($definitions, $relations));
    }

    public function plan(SyncDefinition $definition): SyncPlan
    {
        $locals = $this->byId($definition->localStore->list($definition));
        $remotes = $this->byId($definition->adapter->list());
        $relations = [];
        $claimedLocals = [];
        $claimedRemotes = [];

        $links = $definition->linkStore->all($definition);
        usort($links, static fn (LinkRecord $a, LinkRecord $b): int => $a->createdAt <=> $b->createdAt);

        foreach ($links as $link) {
            $local = $locals[$link->localId] ?? null;
            $remote = $remotes[$link->remoteId] ?? null;

            if (isset($claimedLocals[$link->localId]) || isset($claimedRemotes[$link->remoteId])) {
                $relations[] = new SyncRelation($definition->key, SyncOperation::LocalUnlink, 'Duplicate link: an older one is kept.', $local, $remote, $link);
            } elseif (! $remote) {
                $relations[] = new SyncRelation($definition->key, SyncOperation::LocalUnlink, 'The remote item is gone.', $local, null, $link);
            } elseif (! $local) {
                $relations[] = new SyncRelation($definition->key, SyncOperation::LocalUnlink, 'The local entity is gone or no longer covered.', null, $remote, $link);
            } elseif (! $definition->isRemoteExcluded($remote)) {
                $pair = $this->planPair($definition, $local, $remote, $link);

                if ($pair) {
                    $relations[] = $pair;
                }
            }

            // Claimed even when unlinked, so neither side is matched again before the next run.
            $claimedLocals[$link->localId] = true;
            $claimedRemotes[$link->remoteId] = true;
        }

        $unlinkedLocals = array_filter(
            array_diff_key($locals, $claimedLocals),
            static fn (LocalItem $local): bool => ! $definition->isLocalExcluded($local)
        );
        $unlinkedRemotes = array_filter(
            array_diff_key($remotes, $claimedRemotes),
            static fn (RemoteItem $remote): bool => ! $definition->isRemoteExcluded($remote)
        );

        return new SyncPlan([...$relations, ...$this->planUnlinked($definition, $unlinkedLocals, $unlinkedRemotes)]);
    }

    /**
     * Plans a single local entity without listing the remote, for calls made
     * in a request (a user just saved their profile). Without a link, finding
     * its remote item needs an adapter able to search; otherwise the entity is
     * left to the next full run rather than risk creating a duplicate.
     */
    public function planOne(SyncDefinition $definition, LocalItem $local): SyncPlan
    {
        $link = $definition->linkStore->findByLocal($definition, $local->id);

        if ($link) {
            $remote = $definition->adapter->get($link->remoteId);

            if (! $remote) {
                return new SyncPlan([new SyncRelation($definition->key, SyncOperation::LocalUnlink, 'The remote item is gone.', $local, null, $link)]);
            }

            $pair = $definition->isRemoteExcluded($remote) ? null : $this->planPair($definition, $local, $remote, $link);

            return new SyncPlan($pair ? [$pair] : []);
        }

        if ($definition->isLocalExcluded($local)) {
            return new SyncPlan();
        }

        if (! $definition->adapter instanceof SearchableRemoteAdapterInterface) {
            return new SyncPlan([new SyncRelation($definition->key, SyncOperation::Unmatched, 'Not linked, and the remote cannot be searched: left to the next full run.', $local)]);
        }

        $candidates = [];
        foreach ($definition->matchRules as $rule) {
            if ($rule instanceof ExactFieldRule && null !== $local->get($rule->localField)) {
                foreach ($definition->adapter->findBy($rule->remoteField, $local->get($rule->localField)) as $remote) {
                    if (! $definition->isRemoteExcluded($remote) && ! $definition->linkStore->findByRemote($definition, $remote->id)) {
                        $candidates[$remote->id] = $remote;
                    }
                }
            }
        }

        return new SyncPlan($this->planUnlinked($definition, [$local->id => $local], $candidates, orphanRemotes: false));
    }

    private function planPair(SyncDefinition $definition, LocalItem $local, RemoteItem $remote, LinkRecord $link): ?SyncRelation
    {
        if ($definition->isLocalExcluded($local)) {
            return match ($definition->excludedLocal) {
                ExcludedLocalPolicy::Ignore => null,
                ExcludedLocalPolicy::DisableRemote => $definition->adapter instanceof DisablableRemoteAdapterInterface && $definition->adapter->isDisabled($remote)
                    ? null
                    : new SyncRelation($definition->key, SyncOperation::RemoteDisable, 'The local entity is excluded.', $local, $remote, $link),
                ExcludedLocalPolicy::RemoveRemote => new SyncRelation($definition->key, SyncOperation::RemoteRemove, 'The local entity is excluded.', $local, $remote, $link),
            };
        }

        $localChanged = $remoteChanged = true;
        if (null !== $link->lastSyncedHash) {
            $localChanged = SyncValueHelper::hash($definition->fields, $local->fields, false) !== $link->lastSyncedHash;
            $remoteChanged = SyncValueHelper::hash($definition->fields, $remote->fields, true) !== $link->lastSyncedHash;
        }

        $diffs = [];
        foreach ($definition->fields as $mapping) {
            $localValue = $local->get($mapping->localField);
            $remoteValue = $remote->get($mapping->remoteField);

            if (! SyncValueHelper::same($localValue, $remoteValue)) {
                $diffs[] = new FieldDiff(
                    $mapping->localField,
                    $mapping->remoteField,
                    $localValue,
                    $remoteValue,
                    $this->target($definition, $mapping->direction, $localChanged, $remoteChanged),
                );
            }
        }

        if ([] === $diffs) {
            return new SyncRelation($definition->key, SyncOperation::UpToDate, 'Both sides agree.', $local, $remote, $link);
        }

        foreach ($diffs as $diff) {
            if (SyncSide::None === $diff->target) {
                return new SyncRelation($definition->key, SyncOperation::Conflict, 'Both sides changed since the last sync.', $local, $remote, $link, $diffs);
            }
        }

        foreach ($diffs as $diff) {
            if (SyncSide::Local === $diff->target) {
                return new SyncRelation($definition->key, SyncOperation::LocalUpdate, 'The remote holds newer values.', $local, $remote, $link, $diffs);
            }
        }

        return new SyncRelation($definition->key, SyncOperation::RemoteUpdate, 'The application holds newer values.', $local, $remote, $link, $diffs);
    }

    private function target(SyncDefinition $definition, FieldDirection $direction, bool $localChanged, bool $remoteChanged): SyncSide
    {
        return match (true) {
            FieldDirection::LocalToRemote === $direction => SyncSide::Remote,
            FieldDirection::RemoteToLocal === $direction => SyncSide::Local,
            $localChanged && ! $remoteChanged => SyncSide::Remote,
            $remoteChanged && ! $localChanged => SyncSide::Local,
            ConflictPolicy::LocalWins === $definition->conflict => SyncSide::Remote,
            ConflictPolicy::RemoteWins === $definition->conflict => SyncSide::Local,
            default => SyncSide::None,
        };
    }

    /**
     * @param array<string, LocalItem> $locals
     * @param array<string, RemoteItem> $remotes
     *
     * @return SyncRelation[]
     */
    private function planUnlinked(SyncDefinition $definition, array $locals, array $remotes, bool $orphanRemotes = true): array
    {
        $result = $this->matcher->match($definition, array_values($locals), array_values($remotes));
        $relations = [];
        $settledLocals = [];
        $settledRemotes = [];

        foreach ($result->matches as $localId => $match) {
            $relations[] = new SyncRelation(
                $definition->key,
                SyncOperation::LocalLink,
                sprintf('Matched on %s.', $match['rule']),
                $locals[$localId],
                $remotes[$match['remoteId']],
                context: ['rule' => $match['rule'], 'score' => $match['score']],
            );
            $settledLocals[$localId] = $settledRemotes[$match['remoteId']] = true;
        }

        foreach ($result->conflicts as $conflict) {
            foreach ($conflict['localIds'] as $localId) {
                $relations[] = new SyncRelation(
                    $definition->key,
                    SyncOperation::Conflict,
                    sprintf('Ambiguous on %s: several items share the value.', $conflict['rule']),
                    $locals[$localId],
                    context: ['rule' => $conflict['rule'], 'localIds' => $conflict['localIds'], 'remoteIds' => $conflict['remoteIds']],
                );
                $settledLocals[$localId] = true;
            }

            foreach ($conflict['remoteIds'] as $remoteId) {
                $settledRemotes[$remoteId] = true;
            }
        }

        foreach ($result->candidates as $candidate) {
            $relations[] = new SyncRelation(
                $definition->key,
                SyncOperation::Candidate,
                sprintf('Looks alike on %s (%.2f): to confirm.', $candidate['rule'], $candidate['score']),
                $locals[$candidate['localId']],
                $remotes[$candidate['remoteId']],
                context: ['rule' => $candidate['rule'], 'score' => $candidate['score']],
            );
            $settledLocals[$candidate['localId']] = $settledRemotes[$candidate['remoteId']] = true;
        }

        foreach (array_diff_key($locals, $settledLocals) as $local) {
            $operation = match ($definition->orphanLocal) {
                OrphanLocalPolicy::CreateRemote => SyncOperation::RemoteCreate,
                OrphanLocalPolicy::Report => SyncOperation::Unmatched,
                OrphanLocalPolicy::Ignore => null,
            };

            if ($operation) {
                $relations[] = new SyncRelation($definition->key, $operation, 'No remote item matches.', $local);
            }
        }

        if ($orphanRemotes) {
            foreach (array_diff_key($remotes, $settledRemotes) as $remote) {
                $operation = match ($definition->orphanRemote) {
                    OrphanRemotePolicy::CreateLocal => SyncOperation::LocalCreate,
                    OrphanRemotePolicy::RemoveRemote => SyncOperation::RemoteRemove,
                    OrphanRemotePolicy::Report => SyncOperation::Unmatched,
                    OrphanRemotePolicy::Ignore => null,
                };

                if ($operation) {
                    $relations[] = new SyncRelation($definition->key, $operation, 'No local entity matches.', null, $remote);
                }
            }
        }

        return $relations;
    }

    /**
     * @param SyncDefinition[] $definitions
     * @param SyncRelation[] $relations
     *
     * @return SyncRelation[]
     */
    private function postponeConcurrentLocalWrites(array $definitions, array $relations): array
    {
        $definitionsByKey = [];
        foreach ($definitions as $definition) {
            $definitionsByKey[$definition->key] = $definition;
        }

        $writers = [];
        foreach ($relations as $index => $relation) {
            $definition = $definitionsByKey[$relation->definitionKey];

            foreach ($this->localWriteKeys($definition, $relation) as $key) {
                if (isset($writers[$key]) && $writers[$key] !== $relation->definitionKey) {
                    $relations[$index] = $relation->withOperation(
                        SyncOperation::Postponed,
                        sprintf('Waits for "%s", which writes the same local entity in this run.', $writers[$key])
                    );

                    continue 2;
                }
            }

            foreach ($this->localWriteKeys($definition, $relation) as $key) {
                $writers[$key] = $relation->definitionKey;
            }
        }

        return $relations;
    }

    /**
     * Keys identifying the local entity a relation writes: its id for an
     * update, the values its creation would be matched on for a creation.
     *
     * @return string[]
     */
    private function localWriteKeys(SyncDefinition $definition, SyncRelation $relation): array
    {
        if (SyncOperation::LocalUpdate === $relation->operation && $relation->local) {
            return [$definition->localClass.'#'.$relation->local->id];
        }

        if (SyncOperation::LocalCreate !== $relation->operation || ! $relation->remote) {
            return [];
        }

        $keys = [];
        foreach ($definition->matchRules as $rule) {
            $value = $rule instanceof ExactFieldRule ? $rule->remoteKey($relation->remote) : null;

            if (null !== $value) {
                $keys[] = $definition->localClass.'@'.$rule->localField.'='.$value;
            }
        }

        return $keys;
    }

    /**
     * @template T of LocalItem|RemoteItem
     *
     * @param iterable<T> $items
     *
     * @return array<string, T>
     */
    private function byId(iterable $items): array
    {
        $byId = [];
        foreach ($items as $item) {
            $byId[$item->id] = $item;
        }

        return $byId;
    }
}
