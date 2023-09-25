<?php

namespace Wexample\SymfonyDataSync\Message;

readonly class EntitySyncMessage
{
    public function __construct(private array $data)
    {
    }

    public function getData(): array
    {
        return $this->data;
    }
}
