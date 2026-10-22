<?php

namespace Wexample\SymfonyDataSync\Class;

use Wexample\SymfonyDataSync\Enum\FieldDirection;

final readonly class FieldMapping
{
    public function __construct(
        public string $localField,
        public string $remoteField,
        public FieldDirection $direction = FieldDirection::LocalToRemote,
    ) {
    }
}
