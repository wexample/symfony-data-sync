<?php

namespace Wexample\SymfonyDataSync\Testing;

use LogicException;
use Wexample\SymfonyDataSync\Class\RemoteItem;
use Wexample\SymfonyDataSync\Interface\DisablableRemoteAdapterInterface;

/**
 * A remote held in an array, for tests of definitions and adapters' callers.
 * Every write is recorded in $calls, in order.
 */
class InMemoryRemoteAdapter implements DisablableRemoteAdapterInterface
{
    /**
     * @var array<string, array<string, mixed>>
     */
    public array $items = [];

    /**
     * @var array<int, array{0: string, 1: string|null}>
     */
    public array $calls = [];

    private int $nextId = 1;

    /**
     * @param array<string, array<string, mixed>> $items fields by remote id
     * @param bool $refuseListing makes list() throw, to prove a path never lists everything
     */
    public function __construct(
        array $items = [],
        public bool $refuseListing = false,
    ) {
        $this->items = $items;
    }

    public function list(): iterable
    {
        if ($this->refuseListing) {
            throw new LogicException('This remote must not be listed.');
        }

        foreach ($this->items as $id => $fields) {
            yield new RemoteItem((string) $id, $fields);
        }
    }

    public function get(string $id): ?RemoteItem
    {
        return isset($this->items[$id]) ? new RemoteItem($id, $this->items[$id]) : null;
    }

    public function create(array $fields): RemoteItem
    {
        $id = 'remote-'.$this->nextId++;
        $this->items[$id] = $fields;
        $this->calls[] = ['create', $id];

        return new RemoteItem($id, $fields);
    }

    public function update(string $id, array $fields): RemoteItem
    {
        $this->items[$id] = $fields + $this->items[$id];
        $this->calls[] = ['update', $id];

        return new RemoteItem($id, $this->items[$id]);
    }

    public function remove(string $id): void
    {
        unset($this->items[$id]);
        $this->calls[] = ['remove', $id];
    }

    public function disable(string $id): void
    {
        $this->items[$id]['active'] = false;
        $this->calls[] = ['disable', $id];
    }
}
