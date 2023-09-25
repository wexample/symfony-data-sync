<?php

namespace Wexample\SymfonyDataSync\Class;

use JetBrains\PhpStorm\Pure;
use Wexample\SymfonyDataSync\Service\DataSyncManager\EntitiesSyncManager;
use Wexample\SymfonyDataSync\Service\DataSyncManager\RemoteSyncManager;
use Wexample\SymfonyHelpers\Entity\Interfaces\AbstractEntityInterface;
use Wexample\SymfonyHelpers\Helper\ClassHelper;

class Map
{
    /**
     * @var Relation[]
     */
    private array $relations = [];

    public function __construct(
        public string $entityClassName,
        public string $remoteOrphansSyncMode,
    ) {
    }

    public function toFlatDataset(string $filter = null): FlatDataset
    {
        $data = [];

        $key = ClassHelper::getTableizedName(
            $this->entityClassName
        );

        $relations = $filter
            ? $this->getFilteredRelations($filter) : $this->getRelations();

        foreach ($relations as $relation) {
            $line = [];

            if ($localPart = $relation->getLocalPart()) {
                $line[$key] =
                    $this->buildRelationPartSummary($localPart);
            }

            foreach ($relation->getRemoteParts() as $remoteKey => $remotePart) {
                $line[ClassHelper::getTableizedName($remoteKey)]
                    = $this->buildRelationPartSummary($remotePart);
            }

            $data[] = $line;
        }

        return new FlatDataset($data);
    }

    /**
     * @return Relation[]
     */
    public function getFilteredRelations(string $filter): array
    {
        $relationsFiltered = [];

        foreach ($this->relations as $relation) {
            if ($relation->hasOperation($filter)) {
                $relationsFiltered[] = $relation;
            }
        }

        return $relationsFiltered;
    }

    public function getRelations(): array
    {
        return $this->relations;
    }

    /**
     * @param Relation[] $relations
     */
    public function setRelations(array $relations)
    {
        $this->relations = $relations;
    }

    #[Pure]
    private function buildRelationPartSummary(RelationPart $relationPart): string
    {
        $line = $relationPart->getName()
            .PHP_EOL.'  '.$relationPart->getOperation();

        if ($relationPart->getResponse()) {
            $line .= PHP_EOL.'  '.$relationPart->getResponse();
        }

        return $line.PHP_EOL;
    }

    #[Pure]
    public function getNonUpToDateRelations(): array
    {
        $relationsFiltered = [];

        foreach ($this->relations as $relation) {
            if (!$relation->allPartAreUpToDate()) {
                $relationsFiltered[] = $relation;
            }
        }

        return $relationsFiltered;
    }

    public function applyFilterLocalEntity(AbstractEntityInterface $entity)
    {
        $relationsFiltered = [];

        foreach ($this->relations as $relation) {
            $object = $relation->getLocalPart()?->getObject();

            if (($object instanceof AbstractEntityInterface)
                && $object->getId() === $entity->getId()) {
                $relationsFiltered[] = $relation;
            }
        }

        $this->relations = $relationsFiltered;
    }

    public function createRelationFromLocalEntity(
        AbstractEntityInterface $entity,
        string $name,
        string $operation,
        EntitiesSyncManager|RemoteSyncManager $manager
    ): ?RelationPart {
        $relation = new Relation();

        $part = $relation->addLocalEntity(
            $entity,
            $name,
            $operation,
            $manager
        );

        $this->relations[] = $relation;

        return $part;
    }

    public function createRelationFromRemoteItem(
        RemoteSyncManager $remote,
        string $name,
        mixed $remoteItem,
        mixed $operation,
        EntitiesSyncManager|RemoteSyncManager $manager
    ): ?RelationPart {
        $relation = new Relation();

        $item = $relation->addRemoteRelationPart(
            $remote,
            $name,
            $remoteItem,
            $operation,
            $manager
        );

        $this->relations[] = $relation;

        return $item;
    }

    public function getRelationPartForLocalEntity(
        AbstractEntityInterface $entity
    ): ?RelationPart {
        foreach ($this->getRelations() as $relation) {
            if ($localPart = $relation->getLocalPart()) {
                if ($localPart->getObject()->getId() === $entity->getId()) {
                    return $localPart;
                }
            }
        }

        return null;
    }

    public function getRelationPartForRemoteItem(
        RemoteSyncManager $remote,
        mixed $remoteItem
    ): ?RelationPart {
        foreach ($this->getRelations() as $relation) {
            if ($relation->getRemotePart($remote)) {
                $part = $relation->getRemotePart($remote);

                if ($part->getObject() === $remoteItem) {
                    return $part;
                }
            }
        }

        return null;
    }
}
