<?php

namespace Wexample\SymfonyDataSync\Class;

use Symfony\Component\Uid\Uuid;
use Wexample\SymfonyDataSync\Service\DataSyncManager\EntitiesSyncManager;

class RelationPartLocal extends RelationPart
{
    public function getPart(): string
    {
        return EntitiesSyncManager::OPERATIONS_PART_LOCAL;
    }

    public function getObjectId(): ?Uuid
    {
        $object = $this->getObject();

        return ($object instanceof RelationItemPlaceHolder)
            ? null
            : $this->getObject()?->getId();
    }
}
