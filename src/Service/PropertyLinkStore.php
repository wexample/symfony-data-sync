<?php

namespace Wexample\SymfonyDataSync\Service;

use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Wexample\SymfonyDataSync\Class\LinkRecord;
use Wexample\SymfonyDataSync\Class\SyncDefinition;
use Wexample\SymfonyDataSync\Interface\LinkStoreInterface;
use Wexample\SymfonyDataSync\Interface\LocalStoreInterface;

/**
 * Keeps the remote id in a property of the local entity, for applications that
 * already have such a column (network's User::$rocketChatId). It stores no
 * hash, so every differing "both" field falls to the conflict policy; and a
 * column cannot forbid two entities holding the same id, which the planner
 * then reports as duplicates.
 */
class PropertyLinkStore implements LinkStoreInterface
{
    private PropertyAccessorInterface $accessor;

    public function __construct(
        private readonly LocalStoreInterface $localStore,
        private readonly string $property,
    ) {
        $this->accessor = PropertyAccess::createPropertyAccessor();
    }

    public function all(SyncDefinition $definition): array
    {
        $records = [];

        foreach ($this->localStore->list($definition) as $item) {
            $remoteId = $this->accessor->getValue($item->entity, $this->property);

            if (null !== $remoteId && '' !== $remoteId) {
                $records[] = new LinkRecord($item->id, (string) $remoteId);
            }
        }

        return $records;
    }

    public function findByLocal(SyncDefinition $definition, string $localId): ?LinkRecord
    {
        $item = $this->localStore->find($definition, $localId);
        $remoteId = $item ? $this->accessor->getValue($item->entity, $this->property) : null;

        return null === $remoteId || '' === $remoteId ? null : new LinkRecord($localId, (string) $remoteId);
    }

    public function findByRemote(SyncDefinition $definition, string $remoteId): ?LinkRecord
    {
        foreach ($this->all($definition) as $record) {
            if ($record->remoteId === $remoteId) {
                return $record;
            }
        }

        return null;
    }

    public function link(SyncDefinition $definition, string $localId, string $remoteId, ?string $hash = null): void
    {
        $this->write($definition, $localId, $remoteId);
    }

    public function unlink(SyncDefinition $definition, string $localId, string $remoteId): void
    {
        $this->write($definition, $localId, null);
    }

    public function touch(SyncDefinition $definition, string $localId, string $remoteId, string $hash): void
    {
        // Nowhere to keep a hash: the column only holds the remote id.
    }

    private function write(SyncDefinition $definition, string $localId, ?string $remoteId): void
    {
        $item = $this->localStore->find($definition, $localId);

        if ($item) {
            $this->localStore->update($definition, $item, [$this->property => $remoteId]);
        }
    }
}
