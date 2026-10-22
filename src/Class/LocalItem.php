<?php

namespace Wexample\SymfonyDataSync\Class;

/**
 * A local entity as the planner sees it: its id as a string, and the values
 * of the fields a definition maps and matches on.
 */
final readonly class LocalItem
{
    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(
        public string $id,
        public array $fields,
        public object $entity,
    ) {
    }

    public function get(string $field): mixed
    {
        return $this->fields[$field] ?? null;
    }
}
