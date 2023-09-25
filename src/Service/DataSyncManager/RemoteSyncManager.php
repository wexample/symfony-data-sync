<?php

namespace Wexample\SymfonyDataSync\Service\DataSyncManager;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Wexample\SymfonyDataSync\Class\Map;
use Wexample\SymfonyDataSync\Class\Relation;
use Wexample\SymfonyDataSync\Class\RelationItemPlaceHolder;
use Wexample\SymfonyDataSync\Class\RelationPart;
use Wexample\SymfonyHelpers\Entity\Interfaces\AbstractEntityInterface;

abstract class RemoteSyncManager
{
    protected EntitiesSyncManager $entitiesSyncManager;

    public function __construct(
        protected EntityManagerInterface $em,
        protected MessageBusInterface $messageBus
    ) {
    }

    public function init(
        Map $map
    ) {
        // i.e. connect to remote database.
    }

    public function mapFromAllRemotes(
        Map $map
    ) {
        $remoteItems = $this->findRemoteItems();

        // Execute on all remote, even already found by entity,
        // as remote item may need update.
        foreach ($remoteItems as $remoteItem) {
            // Search if an entry already exists.
            $relationPart = $map->getRelationPartForRemoteItem($this, $remoteItem);

            if (!$relationPart) {
                // If manager is able to find an entity for this remote,
                // reuse created relation to complete it.
                if ($entity = $this->getEntityForRemote($remoteItem)) {
                    $relationPart = $map
                        ->getRelationPartForLocalEntity($entity)
                        ->getParentRelation()
                        ->addRemoteRelationPart(
                            $this,
                            $this->buildRemoteItemName($remoteItem),
                            $remoteItem,
                            EntitiesSyncManager::OPERATION_UP_TO_DATE,
                            $this
                        );
                }
                // Create a new relation for orphan remote item.
                else {
                    $relationPart = $map
                        ->createRelationFromRemoteItem(
                            $this,
                            $this->buildRemoteItemName($remoteItem),
                            $remoteItem,
                            EntitiesSyncManager::OPERATION_UP_TO_DATE,
                            $this
                        );
                }
            }

            $operationRemote = $this->mapRemoteItem(
                $relationPart->getParentRelation(),
                $remoteItem,
                $map->remoteOrphansSyncMode
            );

            $relationPart->setOperation(
                $operationRemote,
                $this
            );
        }
    }

    abstract public function findRemoteItems(): array;

    abstract public function getEntityForRemote(mixed $item): ?AbstractEntityInterface;

    abstract public function buildRemoteItemName(mixed $item): string;

    public function mapRemoteItem(
        Relation $relation,
        mixed $remoteItem,
        string $remoteOrphansSyncMode
    ): string {
        // Use entity set to relation from previous local entity mapping.
        /** @var AbstractEntityInterface $entity */
        $entity = $relation->getLocalPart()?->getObject();

        if (!$entity) {
            if ($this->isRemoteItemMightBeSync($remoteItem)) {
                if (
                    EntitiesSyncManager::OPERATIONS_PART_LOCAL
                    === EntitiesSyncManager::getOperationPart($remoteOrphansSyncMode)
                ) {
                    $relation->addLocalEntityPlaceHolder(
                        EntitiesSyncManager::OPERATION_LOCAL_CREATE,
                        $this
                    );

                    return EntitiesSyncManager::OPERATION_UP_TO_DATE;
                }

                return $remoteOrphansSyncMode;
            }

            return EntitiesSyncManager::OPERATION_UP_TO_DATE;
        }

        // Entity exists, but expect to not.
        if (!$this->isLocalEntityShouldBeSync($entity)) {
            return EntitiesSyncManager::OPERATION_REMOTE_REMOVE;
        }

        if ($this->shouldRemoteItemBeUpdatedAccordingLocalEntity($remoteItem, $entity)) {
            return EntitiesSyncManager::OPERATION_REMOTE_UPDATE;
        }

        return EntitiesSyncManager::OPERATION_UP_TO_DATE;
    }

    /**
     * i.e : We don't want admin protected remote users to have a local entity.
     */
    public function isRemoteItemMightBeSync(mixed $item): bool
    {
        return true;
    }

    /**
     * i.e : We don't want disabled users to have a remote instance.
     */
    public function isLocalEntityShouldBeSync(AbstractEntityInterface $entity): bool
    {
        return true;
    }

    protected function shouldRemoteItemBeUpdatedAccordingLocalEntity(
        mixed $remoteItem,
        AbstractEntityInterface $entity
    ): bool {
        // i.e. Remote username is not the same as local.
        return false;
    }

    abstract public function getRemoteItemById(mixed $id): mixed;

    abstract public function getRemoteItemId(mixed $remoteItem): mixed;

    public function getLocalEntityById(int $id): ?AbstractEntityInterface
    {
        $repo = $this->em->getRepository(
            $this->entitiesSyncManager->getEntityClassName()
        );

        return $repo->find($id);
    }

    public function getLocalEntityId(AbstractEntityInterface $entity): int
    {
        return $entity->getId();
    }

    public function mapLocalEntity(
        AbstractEntityInterface $entity,
        Relation $relation
    ): string {
        $isRemoteExists = $this->hasRemoteItemForEntity($entity);

        if (!$isRemoteExists) {
            // Remote does not exist, as expected.
            if (!$this->isLocalEntityShouldBeSync($entity)) {
                return EntitiesSyncManager::OPERATION_UP_TO_DATE;
            }

            if ($remoteItem = $this->recoverRemoteItem($entity)) {
                // Say that remote item has been found.
                $this
                    ->addRelationPartForRemoteItem(
                        $remoteItem,
                        $relation,
                        EntitiesSyncManager::OPERATION_UP_TO_DATE,
                        $this
                    );

                return EntitiesSyncManager::OPERATION_LOCAL_RECOVER;
            }

            $this->addRelationPartPlaceholderForRemoteItem(
                $relation,
                EntitiesSyncManager::OPERATION_REMOTE_CREATE
            );

            return EntitiesSyncManager::OPERATION_UP_TO_DATE;
        }

        $remoteItem = $this->getRemoteItemForEntity($entity);

        if ($this->shouldLocalEntityBeUpdatedAccordingRemoteItem($entity, $remoteItem)) {
            return EntitiesSyncManager::OPERATION_LOCAL_UPDATE;
        }

        return EntitiesSyncManager::OPERATION_UP_TO_DATE;
    }

    abstract public function hasRemoteItemForEntity(AbstractEntityInterface $entity): bool;

    public function recoverRemoteItem(
        AbstractEntityInterface $entity
    ): mixed {
        // Try to find out remote item from orphan entity data.
        return null;
    }

    public function addRelationPartForRemoteItem(
        mixed $remoteItem,
        Relation $relation,
        string $operation,
        EntitiesSyncManager|RemoteSyncManager $manager
    ): RelationPart {
        return $relation->addRemoteRelationPart(
            $this,
            $this->buildRemoteItemName($remoteItem),
            $remoteItem,
            $operation,
            $manager
        );
    }

    public function addRelationPartPlaceholderForRemoteItem(
        Relation $relation,
        string $operation
    ): RelationPart {
        return $this->addRelationPartForRemoteItem(
            new RelationItemPlaceHolder($operation),
            $relation,
            $operation,
            $this
        );
    }

    abstract public function getRemoteItemForEntity(AbstractEntityInterface $entity): mixed;

    protected function shouldLocalEntityBeUpdatedAccordingRemoteItem(
        AbstractEntityInterface $entity,
        mixed $remoteItem
    ): bool {
        // i.e. Local user posts counter is not up-to-date with remote value.
        return false;
    }

    public function setLocalSyncManager(EntitiesSyncManager $syncManager)
    {
        $this->entitiesSyncManager = $syncManager;
    }

    public function executeLocalEntityOperation(
        AbstractEntityInterface|RelationItemPlaceHolder $entity,
        string $operation,
        Relation $relation
    ): string {
        $remoteItem = $relation->getRemotePart($this)?->getObject();

        return match ($operation) {
            EntitiesSyncManager::OPERATION_LOCAL_CREATE => $this->canCreateLocalEntityFromRemoteItem($remoteItem)
                ? $this->operationCreateLocalEntityFromRemote($remoteItem)
                : EntitiesSyncManager::RESPONSE_TYPE_ERROR,
            EntitiesSyncManager::OPERATION_LOCAL_RECOVER => $this->operationAttachLocalEntityToRemoteItem(
                $entity,
                $remoteItem
            ),
            default => EntitiesSyncManager::RESPONSE_TYPE_NOTHING_TO_DO,
        };
    }

    abstract public function canCreateLocalEntityFromRemoteItem(mixed $item): bool;

    abstract public function operationCreateLocalEntityFromRemote($item): string;

    abstract public function operationAttachLocalEntityToRemoteItem(
        AbstractEntityInterface $relatedEntity,
        mixed $item
    ): string;

    public function executeRemoteItemOperation(
        mixed $remoteItem,
        string $operation,
        Relation $relation
    ): string {
        $localEntity = $relation->getLocalPart()?->getObject();

        return match ($operation) {
            EntitiesSyncManager::OPERATION_REMOTE_CREATE => $this->operationCreateRemoteItem(
                $localEntity,
            ),
            EntitiesSyncManager::OPERATION_REMOTE_REMOVE => $this->operationRemoveRemoteItem(
                $remoteItem
            ),
            EntitiesSyncManager::OPERATION_REMOTE_UPDATE => $this->operationUpdateRemoteItemAccordingToLocalEntity(
                $remoteItem,
                $localEntity
            ),
            default => EntitiesSyncManager::RESPONSE_TYPE_NOTHING_TO_DO,
        };
    }

    abstract public function operationCreateRemoteItem(
        AbstractEntityInterface $entity
    ): string;

    abstract public function operationRemoveRemoteItem(
        mixed $item
    ): string;

    protected function operationUpdateRemoteItemAccordingToLocalEntity(
        mixed $remoteItem,
        AbstractEntityInterface $entity
    ): string {
        // i.e. Change remote username to match local one.
        return EntitiesSyncManager::RESPONSE_TYPE_NOTHING_TO_DO;
    }
}
