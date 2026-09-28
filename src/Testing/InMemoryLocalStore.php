<?php

namespace Wexample\SymfonyDataSync\Testing;

use stdClass;
use Wexample\SymfonyDataSync\Class\LocalItem;
use Wexample\SymfonyDataSync\Class\SyncDefinition;
use Wexample\SymfonyDataSync\Interface\LocalStoreInterface;

/**
 * Local entities held as plain objects, whatever the definition's class, so
 * scenarios run without a database.
 */
class InMemoryLocalStore implements LocalStoreInterface
{
    /**
     * @var array<string, stdClass>
     */
    public array $entities = [];

    private int $nextId = 1;

    /**
     * @param array<string, array<string, mixed>> $entities fields by local id
     */
    public function __construct(array $entities = [])
    {
        foreach ($entities as $id => $fields) {
            $this->entities[(string) $id] = (object) $fields;
        }
    }

    public function list(SyncDefinition $definition): iterable
    {
        foreach ($this->entities as $id => $entity) {
            if ($this->matchesFilter($entity, $definition->localFilter)) {
                yield new LocalItem((string) $id, (array) $entity, $entity);
            }
        }
    }

    public function find(SyncDefinition $definition, string $id): ?LocalItem
    {
        return isset($this->entities[$id]) ? new LocalItem($id, (array) $this->entities[$id], $this->entities[$id]) : null;
    }

    public function create(SyncDefinition $definition, array $fields): LocalItem
    {
        $id = 'local-'.$this->nextId++;
        $this->entities[$id] = (object) $fields;

        return new LocalItem($id, $fields, $this->entities[$id]);
    }

    public function update(SyncDefinition $definition, LocalItem $item, array $fields): LocalItem
    {
        foreach ($fields as $field => $value) {
            $this->entities[$item->id]->{$field} = $value;
        }

        return new LocalItem($item->id, (array) $this->entities[$item->id], $this->entities[$item->id]);
    }

    private function matchesFilter(stdClass $entity, array $filter): bool
    {
        foreach ($filter as $field => $value) {
            if (($entity->{$field} ?? null) != $value) {
                return false;
            }
        }

        return true;
    }
}
