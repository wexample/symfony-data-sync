<?php

namespace Wexample\SymfonyDataSync\Interface;

use Wexample\SymfonyDataSync\Class\LinkRecord;
use Wexample\SymfonyDataSync\Class\SyncDefinition;

/**
 * Remembers which local entity is which remote item, per definition.
 */
interface LinkStoreInterface
{
    /**
     * @return LinkRecord[] every link of the definition, duplicates included: finding them is the planner's job
     */
    public function all(SyncDefinition $definition): array;

    public function findByLocal(SyncDefinition $definition, string $localId): ?LinkRecord;

    public function findByRemote(SyncDefinition $definition, string $remoteId): ?LinkRecord;

    public function link(SyncDefinition $definition, string $localId, string $remoteId, ?string $hash = null): void;

    public function unlink(SyncDefinition $definition, string $localId, string $remoteId): void;

    /**
     * Records the values both sides agree on after a sync.
     */
    public function touch(SyncDefinition $definition, string $localId, string $remoteId, string $hash): void;
}
