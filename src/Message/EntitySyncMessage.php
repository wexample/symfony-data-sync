<?php

namespace Wexample\SymfonyDataSync\Message;

class EntitySyncMessage
{
    public function __construct(private readonly array $data)
    {
    }

    public function getData(): array
    {
        return $this->data;
    }
}
