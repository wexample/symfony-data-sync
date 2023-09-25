<?php

namespace Wexample\SymfonyDataSync\MessageHandler;

use App\Service\DataSyncManager\Entity\UserEntitiesSyncManager;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Wexample\SymfonyDataSync\Message\EntitySyncMessage;

class EntitySyncMessageHandler implements MessageHandlerInterface
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
