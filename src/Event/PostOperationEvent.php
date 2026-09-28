<?php

namespace Wexample\SymfonyDataSync\Event;

use Symfony\Contracts\EventDispatcher\Event;
use Wexample\SymfonyDataSync\Class\RelationOutcome;
use Wexample\SymfonyDataSync\Class\SyncDefinition;

/**
 * Dispatched after an operation ran, failed or was skipped: where an app logs
 * or notifies.
 */
final class PostOperationEvent extends Event
{
    public function __construct(
        public readonly SyncDefinition $definition,
        public readonly RelationOutcome $outcome,
    ) {
    }
}
