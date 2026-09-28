<?php

namespace Wexample\SymfonyDataSync\Class;

use Wexample\SymfonyDataSync\Enum\SyncSide;

/**
 * One mapped field whose two sides differ, and the side that will be written:
 * Remote to push the local value, Local to pull the remote one, None when the
 * conflict policy leaves it to a human.
 */
final readonly class FieldDiff
{
    public function __construct(
        public string $localField,
        public string $remoteField,
        public mixed $localValue,
        public mixed $remoteValue,
        public SyncSide $target,
    ) {
    }
}
