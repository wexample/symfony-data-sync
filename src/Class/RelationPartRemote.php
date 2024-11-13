<?php

namespace Wexample\SymfonyDataSync\Class;

use Wexample\SymfonyDataSync\Service\DataSyncManager\EntitiesSyncManager;

class RelationPartRemote extends RelationPart
{
    public function getPart(): string
    {
        return EntitiesSyncManager::OPERATIONS_PART_REMOTE;
    }

    public function getObjectId(): ?string
    {
        $object = $this->getObject();

        return ($object instanceof RelationItemPlaceHolder)
            ? null
            : $this
                ->getSyncManager()
                ->getRemoteItemId(
                    $this->getObject()
                );
    }
}
