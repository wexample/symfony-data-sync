<?php

namespace Wexample\SymfonyDataSync\Service\DataSyncManager;

use Doctrine\ORM\EntityManagerInterface;

use Doctrine\ORM\QueryBuilder;
use JetBrains\PhpStorm\Pure;
use Symfony\Component\Messenger\MessageBusInterface;
use Wexample\SymfonyDataSync\Class\Map;
use Wexample\SymfonyDataSync\Class\Relation;
use Wexample\SymfonyDataSync\Message\EntitySyncMessage;
use Wexample\SymfonyHelpers\Entity\Interfaces\AbstractEntityInterface;
use Wexample\SymfonyHelpers\Helper\VariableHelper;
use Wexample\SymfonyHelpers\Repository\AbstractRepository;
use Wexample\SymfonyHelpers\Traits\EntityManipulatorTrait;

abstract class EntitiesSyncManager
{
    use EntityManipulatorTrait;

    public const OPERATION_LOCAL_CREATE = 'local_create';

    public const OPERATION_LOCAL_RECOVER = 'local_recover';

    public const OPERATION_LOCAL_REMOVE = 'local_remove';

    public const OPERATION_LOCAL_UPDATE = 'local_update';

    public const OPERATION_REMOTE_CREATE = 'remote_create';

    public const OPERATION_REMOTE_REMOVE = 'remote_remove';

    public const OPERATION_REMOTE_UPDATE = 'remote_update';

    public const OPERATION_UP_TO_DATE = 'up_to_date';

    public const OPERATION_NOT_FOUND = 'no_found';

    public const OPERATION_POSTPONED = 'postponed';

    public const RESPONSE_ENQUEUED = 'enqueued';

    public const RESPONSE_TYPE_ERROR = 'error';

    public const RESPONSE_TYPE_NOTHING_TO_DO = 'nothing_to_do';

    public const RESPONSE_TYPE_SUCCESS = 'success';

    public const OPERATIONS_PART_LOCAL = 'local';

    public const OPERATIONS_PART_REMOTE = 'remote';

    public const OPERATIONS_PARTS = [
        self::OPERATIONS_PART_LOCAL => [
            self::OPERATION_LOCAL_CREATE,
            self::OPERATION_LOCAL_RECOVER,
            self::OPERATION_LOCAL_REMOVE,
            self::OPERATION_LOCAL_UPDATE,
        ],
        self::OPERATIONS_PART_REMOTE => [
            self::OPERATION_REMOTE_CREATE,
            self::OPERATION_REMOTE_REMOVE,
            self::OPERATION_REMOTE_UPDATE,
        ],
    ];
    /**
     * @var RemoteSyncManager[]
     */
    public array $remoteSyncManagers = [];

    #[Pure]
    public function __construct(
        public EntityManagerInterface $entityManager,
        protected MessageBusInterface $messageBus
    ) {
    }

    public static function getOperationPart(string $operation): ?string
    {
        foreach (self::OPERATIONS_PARTS as $partName => $operations) {
            if (in_array($operation, $operations)) {
                return $partName;
            }
        }

        return null;
    }

    public function syncMessage(EntitySyncMessage $message)
    {
        $map = new Map(
            $this->getEntityClassName(),
            // TODO save from message
            self::OPERATION_LOCAL_CREATE
        );

        $this->init($map);

        $relation = $this->unserializeRelation(
            $message->getData()
        );

        $this->runRelationsOperations([
            $relation,
        ]);
    }

    protected function init(Map $map)
    {
        $this->forEachRemoteSyncManager(function (RemoteSyncManager $remoteSyncManager) use (
            $map
        ) {
            $remoteSyncManager->init($map);
        });
    }

    public function forEachRemoteSyncManager(
        callable $callback
    ) {
        foreach ($this->remoteSyncManagers as $remoteSyncManager) {
            $remoteSyncManager->setLocalSyncManager($this);

            $callback($remoteSyncManager);
        }
    }

    public function unserializeRelation(array $relationData): Relation
    {
        $relation = new Relation();
        $localData = $relationData[VariableHelper::LOCAL];

        if (!empty($localData)) {
            $operation = $localData[VariableHelper::OPERATION];
            $manager = $localData[VariableHelper::MANAGER] === static::class ?
                $this : $this->remoteSyncManagers[$localData[VariableHelper::MANAGER]];

            if ($localData[VariableHelper::ID]) {
                $entity = $this->entityManager->getRepository($this->getEntityClassName())->find(
                    $localData[VariableHelper::ID]
                );

                if ($entity) {
                    $relation->addLocalEntity(
                        $entity,
                        $this->buildLocalEntityName($entity),
                        $operation,
                        $manager
                    );
                } else {
                    // TODO Handle error
                }
            } else {
                $relation->addLocalEntityPlaceHolder(
                    $operation,
                    $manager
                );
            }
        }

        foreach ($relationData[VariableHelper::REMOTE] as $remoteData) {
            $operation = $remoteData[VariableHelper::OPERATION];
            $manager = $this->remoteSyncManagers[$remoteData[VariableHelper::MANAGER]];

            if (isset($remoteData[VariableHelper::ID])) {
                $remoteItem = $manager->getRemoteItemById(
                    $remoteData[VariableHelper::ID]
                );

                // Handle error.
                if ($remoteItem) {
                    $relation
                        ->addRemoteRelationPart(
                            $manager,
                            $manager->buildRemoteItemName($remoteItem),
                            $remoteItem,
                            $operation,
                            $manager
                        );
                } else {
                    // TODO Handle error
                }
            } else {
                $manager->addRelationPartPlaceholderForRemoteItem(
                    $relation,
                    $operation
                );
            }
        }

        return $relation;
    }

    abstract public function buildLocalEntityName(AbstractEntityInterface $entity): string;

    /**
     * @param Relation[] $relations
     */
    public function runRelationsOperations(
        array $relations
    ) {
        foreach ($relations as $relation) {
            $localPart = $relation->getLocalPart();

            $hasLocalOperation = null;

            // Supports no local part.
            if ($localPart) {
                $hasLocalOperation = self::OPERATION_UP_TO_DATE !== $localPart->getOperation();
                $response = self::RESPONSE_TYPE_NOTHING_TO_DO;

                if ($hasLocalOperation) {
                    $response = $localPart->getSyncManager()->executeLocalEntityOperation(
                        $localPart->getObject(),
                        $localPart->getOperation(),
                        $relation
                    );
                }

                $localPart->setResponse($response);
            }

            $parts = $relation->getRemoteParts();
            foreach ($parts as $remote => $relationPart) {
                $remoteSyncManager = $this->remoteSyncManagers[$remote];
                $response = self::RESPONSE_TYPE_NOTHING_TO_DO;

                if (!$hasLocalOperation || $localPart->getSyncManager() === $remoteSyncManager) {
                    $response = $remoteSyncManager->executeRemoteItemOperation(
                        $relationPart->getObject(),
                        $relationPart->getOperation(),
                        $relation
                    );
                }

                $relationPart->setResponse($response);
            }
        }
    }

    public function syncSingle(
        AbstractEntityInterface $entity,
        bool $async = false,
        string $remoteOrphansSyncMode = self::OPERATION_LOCAL_CREATE,
        string $operationFilter = null,
        bool $dryRun = false,
        bool $all = false,
    ): Map {
        return $this->sync(
            $async,
            $remoteOrphansSyncMode,
            $operationFilter,
            $dryRun,
            $all,
            $entity,
        );
    }

    public function sync(
        bool $async = false,
        string $remoteOrphansSyncMode = self::OPERATION_LOCAL_CREATE,
        string $operationFilter = null,
        bool $dryRun = false,
        bool $all = false,
        AbstractEntityInterface $singleEntity = null,
    ): Map {
        $map = new Map(
            $this->getEntityClassName(),
            $remoteOrphansSyncMode
        );

        $this->init($map);

        $this->mapLocalToRemote(
            $map
        );

        $this->mapRemoteToLocal(
            $map
        );

        if ($operationFilter) {
            $map->setRelations(
                $map->getFilteredRelations($operationFilter)
            );
        }

        if ($singleEntity) {
            $map->applyFilterLocalEntity($singleEntity);
        }

        if (!$all) {
            $map->setRelations(
                $map->getNonUpToDateRelations()
            );
        }

        $relations = $map->getRelations();

        if (!$dryRun) {
            if ($async) {
                foreach ($relations as $relation) {
                    if ($localPart = $relation->getLocalPart()) {
                        $localPart->setResponse(EntitiesSyncManager::RESPONSE_ENQUEUED);
                    }

                    foreach ($relation->getRemoteParts() as $remotePart) {
                        $remotePart->setResponse(EntitiesSyncManager::RESPONSE_ENQUEUED);
                    }

                    $this->messageBus->dispatch(
                        new EntitySyncMessage(
                            $relation->serialize(),
                        )
                    );
                }
            } else {
                $this->runRelationsOperations(
                    $relations
                );
            }
        }

        return $map;
    }

    public function mapLocalToRemote(
        Map $map
    ) {
        $entities = $this->findEntities();

        /** @var AbstractEntityInterface $entity */
        foreach ($entities as $entity) {
            $this->mapLocalEntityToRemotes(
                $entity,
                $map
            );
        }
    }

    public function findEntities(): array
    {
        return $this->buildEntitiesQuery()->getQuery()->execute();
    }

    public function buildEntitiesQuery(): QueryBuilder
    {
        /** @var AbstractRepository $repo */
        $repo = $this->entityManager->getRepository($this->getEntityClassName());

        return $repo->createOrGetQueryBuilder();
    }

    public function mapLocalEntityToRemotes(
        AbstractEntityInterface $entity,
        Map $map
    ) {
        $relationPartLocal = $map->createRelationFromLocalEntity(
            $entity,
            $this->buildLocalEntityName($entity),
            EntitiesSyncManager::OPERATION_UP_TO_DATE,
            $this
        );

        $this->forEachRemoteSyncManager(function (
            RemoteSyncManager $remoteSyncManager
        ) use (
            $entity,
            $relationPartLocal
        ) {
            $relation = $relationPartLocal->getParentRelation();

            if (self::OPERATION_UP_TO_DATE === $relation->getLocalPart()->getOperation()) {
                $localEntityOperation = $remoteSyncManager->mapLocalEntity(
                    $entity,
                    $relation
                );

                $remoteItemDefaultOperation = self::OPERATION_UP_TO_DATE;

                // Remote asked for an operation on local entity.
                if (self::OPERATION_UP_TO_DATE !== $localEntityOperation) {
                    $relationPartLocal->setOperation(
                        $localEntityOperation,
                        $remoteSyncManager
                    );

                    // An operation is planned on the entity,
                    // so we cancel all the remote pending operations,
                    // Except those provided by current remote manager.
                    foreach ($relation->getRemoteParts() as $remotePart) {
                        if ($remotePart->getSyncManager() !== $remoteSyncManager
                            && self::OPERATION_UP_TO_DATE !== $remotePart->getOperation()
                        ) {
                            $remotePart->setOperation(
                                self::OPERATION_POSTPONED,
                                $remoteSyncManager
                            );
                        }
                    }

                    // We consider that all checks on remote
                    // may be incomplete until local entity is not up-to-date.
                    $remoteItemDefaultOperation = self::OPERATION_POSTPONED;
                }

                // No custom placeholder has been set on remote item.
                if (!$relation->getRemotePart($remoteSyncManager)) {
                    if ($remoteSyncManager->hasRemoteItemForEntity($entity)) {
                        $remoteItem = $remoteSyncManager->getRemoteItemForEntity($entity);

                        $remoteSyncManager
                            ->addRelationPartForRemoteItem(
                                $remoteItem,
                                $relation,
                                $remoteItemDefaultOperation,
                                $remoteSyncManager
                            );
                    } else {
                        $remoteSyncManager->addRelationPartPlaceholderForRemoteItem(
                            $relation,
                            $remoteItemDefaultOperation
                        );
                    }
                }
            }
            // An operation is planned on the entity,
            // we will check for any update later.
            else {
                $remoteSyncManager->addRelationPartPlaceholderForRemoteItem(
                    $relation,
                    self::OPERATION_POSTPONED
                );
            }
        });
    }

    public function mapRemoteToLocal(
        Map $map
    ) {
        $this->forEachRemoteSyncManager(function (RemoteSyncManager $remoteSyncManager) use (
            $map
        ) {
            $remoteSyncManager->mapFromAllRemotes(
                $map
            );
        });
    }

    /**
     * @param Relation[] $relations
     *
     * @return Relation[]
     */
    public function runSerializedRelations(array $relations): array
    {
        $converted = [];

        foreach ($relations as $relation) {
            $converted[] = $this->unserializeRelation(
                $relation->serialize()
            );
        }

        $this->runRelationsOperations($converted);

        return $converted;
    }
}
