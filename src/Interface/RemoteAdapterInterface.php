<?php

namespace Wexample\SymfonyDataSync\Interface;

use Wexample\SymfonyDataSync\Class\RemoteItem;

/**
 * What a remote must offer to be synchronized. An adapter knows its service's
 * API and nothing of the application's entities: it speaks RemoteItem only.
 */
interface RemoteAdapterInterface
{
    /**
     * @return iterable<RemoteItem> every item the definitions may match
     */
    public function list(): iterable;

    public function get(string $id): ?RemoteItem;

    /**
     * @param array<string, mixed> $fields
     */
    public function create(array $fields): RemoteItem;

    /**
     * @param array<string, mixed> $fields only the fields to change
     */
    public function update(string $id, array $fields): RemoteItem;

    public function remove(string $id): void;
}
