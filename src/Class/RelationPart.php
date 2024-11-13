<?php

namespace Wexample\SymfonyDataSync\Class;

use Wexample\SymfonyDataSync\Service\DataSyncManager\EntitiesSyncManager;
use Wexample\SymfonyDataSync\Service\DataSyncManager\RemoteSyncManager;
use Wexample\SymfonyHelpers\Helper\VariableHelper;

abstract class RelationPart
{
    private ?string $response = null;

    public function __construct(
        private readonly Relation $parentRelation,
        private readonly mixed $name,
        private readonly mixed $object,
        private string $operation,
        private RemoteSyncManager|EntitiesSyncManager $syncManager
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSourceRemotePart(): ?RelationPart
    {
        return $this
            ->getParentRelation()
            ->getRemotePart($this->getSyncManagerClass());
    }

    public function getParentRelation(): Relation
    {
        return $this->parentRelation;
    }

    public function getSyncManagerClass(): RemoteSyncManager|EntitiesSyncManager
    {
        return $this->syncManager;
    }

    public function getSyncManager(): EntitiesSyncManager|RemoteSyncManager
    {
        return $this->syncManager;
    }

    public function getResponse(): ?string
    {
        return $this->response;
    }

    public function setResponse(string $response)
    {
        $this->response = $response;
    }

    public function serialize(): array
    {
        return [
            VariableHelper::ID => $this->getObjectId(),
            VariableHelper::PART => $this->getPart(),
            VariableHelper::OPERATION => $this->getOperation(),
            VariableHelper::MANAGER => $this?->getSyncManagerClass()::class,
        ];
    }

    abstract public function getObjectId(): mixed;

    abstract public function getPart(): string;

    public function getOperation(): ?string
    {
        return $this->operation;
    }

    public function setOperation(
        string $operation,
        EntitiesSyncManager|RemoteSyncManager $syncManager
    ) {
        $this->operation = $operation;
        $this->syncManager = $syncManager;

        $object = $this->getObject();
        if ($object instanceof RelationItemPlaceHolder) {
            $object->setLabel($operation);
        }
    }

    /**
     * Can be local entity or remote object.
     */
    public function getObject(): mixed
    {
        return $this->object;
    }
}
