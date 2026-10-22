<?php

namespace Wexample\SymfonyDataSync\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Wexample\SymfonyDataSync\Class\LinkRecord;
use Wexample\SymfonyDataSync\Class\SyncDefinition;
use Wexample\SymfonyDataSync\Entity\SyncLink;
use Wexample\SymfonyDataSync\Interface\LinkStoreInterface;
use Wexample\SymfonyDataSync\Repository\SyncLinkRepository;

/**
 * The default link store: a sync_link table, one row per linked pair and
 * definition, holding the hash of the values agreed at the last sync.
 */
class DoctrineLinkStore implements LinkStoreInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SyncLinkRepository $repository,
    ) {
    }

    public function all(SyncDefinition $definition): array
    {
        return array_map($this->toRecord(...), $this->repository->findByDefinition($definition->key));
    }

    public function findByLocal(SyncDefinition $definition, string $localId): ?LinkRecord
    {
        $link = $this->repository->findOneBy(['definitionKey' => $definition->key, 'localId' => $localId]);

        return $link ? $this->toRecord($link) : null;
    }

    public function findByRemote(SyncDefinition $definition, string $remoteId): ?LinkRecord
    {
        $link = $this->repository->findOneBy(['definitionKey' => $definition->key, 'remoteId' => $remoteId]);

        return $link ? $this->toRecord($link) : null;
    }

    public function link(SyncDefinition $definition, string $localId, string $remoteId, ?string $hash = null): void
    {
        $link = new SyncLink($definition->key, $definition->localClass, $localId, $remoteId);

        if (null !== $hash) {
            $link->markSynced($hash);
        }

        $this->entityManager->persist($link);
        $this->entityManager->flush();
    }

    public function unlink(SyncDefinition $definition, string $localId, string $remoteId): void
    {
        $link = $this->find($definition, $localId, $remoteId);

        if ($link) {
            $this->entityManager->remove($link);
            $this->entityManager->flush();
        }
    }

    public function touch(SyncDefinition $definition, string $localId, string $remoteId, string $hash): void
    {
        $this->find($definition, $localId, $remoteId)?->markSynced($hash);
        $this->entityManager->flush();
    }

    private function find(SyncDefinition $definition, string $localId, string $remoteId): ?SyncLink
    {
        return $this->repository->findOneBy([
            'definitionKey' => $definition->key,
            'localId' => $localId,
            'remoteId' => $remoteId,
        ]);
    }

    private function toRecord(SyncLink $link): LinkRecord
    {
        return new LinkRecord(
            $link->getLocalId(),
            $link->getRemoteId(),
            $link->getLastSyncedHash(),
            $link->getLastSyncedAt(),
            DateTimeImmutable::createFromInterface($link->getCreatedAt()),
        );
    }
}
