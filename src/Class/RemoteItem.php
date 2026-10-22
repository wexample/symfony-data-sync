<?php

namespace Wexample\SymfonyDataSync\Class;

/**
 * One item of a remote, normalized by its adapter: always known by its id,
 * never by object identity.
 */
final readonly class RemoteItem
{
    /**
     * @param array<string, mixed> $fields the values the definitions map and match on
     * @param mixed $raw the payload as the remote sent it, for adapters that need it back
     */
    public function __construct(
        public string $id,
        public array $fields,
        public mixed $raw = null,
    ) {
    }

    public function get(string $field): mixed
    {
        return $this->fields[$field] ?? null;
    }
}
