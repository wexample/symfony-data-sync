<?php

namespace Wexample\SymfonyDataSync\Interface;

use Wexample\SymfonyDataSync\Class\LocalItem;
use Wexample\SymfonyDataSync\Class\SyncDefinition;

/**
 * Reads and writes the application's side of a definition. The default is
 * Doctrine; the planner only ever sees LocalItem.
 */
interface LocalStoreInterface
{
    /**
     * @return iterable<LocalItem> the entities the definition covers, after its local filter
     */
    public function list(SyncDefinition $definition): iterable;

    public function find(SyncDefinition $definition, string $id): ?LocalItem;

    /**
     * @param array<string, mixed> $fields local field names to values
     */
    public function create(SyncDefinition $definition, array $fields): LocalItem;

    /**
     * @param array<string, mixed> $fields local field names to values
     */
    public function update(SyncDefinition $definition, LocalItem $item, array $fields): LocalItem;
}
