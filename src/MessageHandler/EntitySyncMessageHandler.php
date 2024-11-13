<?php

namespace Wexample\SymfonyDataSync\MessageHandler;

use App\Service\DataSyncManager\Entity\UserEntitiesSyncManager;
use Wexample\SymfonyDataSync\Message\EntitySyncMessage;

#[\Symfony\Component\Messenger\Attribute\AsMessageHandler]
class EntitySyncMessageHandler
{
    public function __construct(
        protected UserEntitiesSyncManager $userEntitiesSyncManager
    ) {
    }
    public function __invoke(EntitySyncMessage $message): void
    {
        $this->userEntitiesSyncManager->syncMessage($message);
    }
}
