<?php

namespace Wexample\SymfonyDataSync\Event;

use Symfony\Contracts\EventDispatcher\Event;
use Wexample\SymfonyDataSync\Class\SyncDefinition;
use Wexample\SymfonyDataSync\Class\SyncRelation;

/**
 * Dispatched before an operation writes anything, dry runs excluded.
 */
final class PreOperationEvent extends Event
{
    public function __construct(
        public readonly SyncDefinition $definition,
        public readonly SyncRelation $relation,
    ) {
    }
}
