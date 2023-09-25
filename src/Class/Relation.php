<?php

namespace Wexample\SymfonyDataSync\Class;

use JetBrains\PhpStorm\Pure;
use Wexample\SymfonyDataSync\Service\DataSyncManager\EntitiesSyncManager;
use Wexample\SymfonyDataSync\Service\DataSyncManager\RemoteSyncManager;
use Wexample\SymfonyHelpers\Entity\Interfaces\AbstractEntityInterface;
use Wexample\SymfonyHelpers\Helper\VariableHelper;

class Relation
{
    private ?RelationPart $localPart = null;

    /** @var RelationPart[] */
    private ?array $remoteParts = [];

    public function getRemotePart(RemoteSyncManager|string $remoteSyncManager): ?RelationPart
    {
        return $this->remoteParts[$remoteSyncManager instanceof RemoteSyncManager ? $remoteSyncManager::class : $remoteSyncManager] ?? null;
    }

    public function addRemoteRelationPart(
        RemoteSyncManager $remoteSyncManager,
        string $name,
        mixed $remoteItem,
        string $operation,
        EntitiesSyncManager|RemoteSyncManager $manager
    ): RelationPartRemote {
        $remoteItem = new RelationPartRemote(
            $this,
            $name,
            $remoteItem,
            $operation,
            $manager
        );

        $this->remoteParts[$remoteSyncManager::class] = $remoteItem;

        return $remoteItem;
    }

    public function addLocalEntityPlaceHolder(
        string $operation,
        EntitiesSyncManager|RemoteSyncManager $manager
    ): RelationPart {
        $placeholder = new RelationItemPlaceHolder($operation);

        return $this->addLocalEntity(
            $placeholder,
            $placeholder->getLabel(),
            $operation,
            $manager
        );
    }

    public function addLocalEntity(
        AbstractEntityInterface|RelationItemPlaceHolder $entity,
        string $name,
        string $operation,
        EntitiesSyncManager|RemoteSyncManager $manager
    ): RelationPartLocal {
        $item = new RelationPartLocal(
            $this,
            $name,
            $entity,
            $operation,
            $manager
        );

        $this->localPart = $item;

        return $item;
    }

    public function hasOperation(string $filter): bool
    {
        if (str_contains((string) $this->localPart?->getOperation(), $filter)) {
            return true;
        }

        foreach ($this->remoteParts as $part) {
            if (str_contains((string) $part->getOperation(), $filter)) {
                return true;
            }
        }

        return false;
    }

    public function serialize(): array
    {
        $data = [
            VariableHelper::LOCAL => null,
            VariableHelper::REMOTE => [],
        ];

        if ($localPart = $this->getLocalPart()) {
            $data[VariableHelper::LOCAL] = $localPart->serialize();
        }

        foreach ($this->getRemoteParts() as $remoteSyncName => $remotePart) {
            $data[VariableHelper::REMOTE][$remoteSyncName] = $remotePart->serialize();
        }

        return $data;
    }

    public function getLocalPart(): ?RelationPart
    {
        return $this->localPart;
    }

    public function getRemoteParts(): array
    {
        return $this->remoteParts;
    }

    #[Pure]
    public function allPartAreUpToDate(): bool
    {
        if (EntitiesSyncManager::OPERATION_UP_TO_DATE !== $this->localPart?->getOperation()) {
            return false;
        }

        foreach ($this->remoteParts as $part) {
            if (EntitiesSyncManager::OPERATION_UP_TO_DATE !== $part->getOperation()) {
                return false;
            }
        }

        return true;
    }
}
