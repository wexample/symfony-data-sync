<?php

namespace Wexample\SymfonyDataSync\Class;

use Wexample\SymfonyDataSync\Enum\SyncOutcome;

final readonly class RelationOutcome
{
    public function __construct(
        public SyncRelation $relation,
        public SyncOutcome $outcome,
        public ?string $message = null,
    ) {
    }
}
