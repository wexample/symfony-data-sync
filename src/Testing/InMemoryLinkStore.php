<?php

namespace Wexample\SymfonyDataSync\Testing;

use DateTimeImmutable;
use Wexample\SymfonyDataSync\Class\LinkRecord;
use Wexample\SymfonyDataSync\Class\SyncDefinition;
use Wexample\SymfonyDataSync\Interface\LinkStoreInterface;

/**
 * Links held in an array. Unlike the Doctrine store it accepts duplicates, so
 * tests can seed the corrupted states the planner must repair.
 */
class InMemoryLinkStore implements LinkStoreInterface
{
    /**
     * @var array<string, LinkRecord[]> by definition key
     */
    public array $links = [];

    public function seed(string $definitionKey, LinkRecord ...$records): self
    {
        $this->links[$definitionKey] = [...($this->links[$definitionKey] ?? []), ...$records];

        return $this;
    }

    public function all(SyncDefinition $definition): array
    {
        return $this->links[$definition->key] ?? [];
    }

    public function findByLocal(SyncDefinition $definition, string $localId): ?LinkRecord
    {
        foreach ($this->all($definition) as $record) {
            if ($record->localId === $localId) {
                return $record;
            }
        }

        return null;
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
        $this->seed($definition->key, new LinkRecord(
            $localId,
            $remoteId,
            $hash,
            null === $hash ? null : new DateTimeImmutable(),
        ));
    }

    public function unlink(SyncDefinition $definition, string $localId, string $remoteId): void
    {
        $this->links[$definition->key] = array_values(array_filter(
            $this->all($definition),
            static fn (LinkRecord $record): bool => $record->localId !== $localId || $record->remoteId !== $remoteId
        ));
    }

    public function touch(SyncDefinition $definition, string $localId, string $remoteId, string $hash): void
    {
        $this->links[$definition->key] = array_map(
            static fn (LinkRecord $record): LinkRecord => $record->localId === $localId && $record->remoteId === $remoteId
                ? new LinkRecord($localId, $remoteId, $hash, new DateTimeImmutable(), $record->createdAt)
                : $record,
            $this->all($definition)
        );
    }
}
