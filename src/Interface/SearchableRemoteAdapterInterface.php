<?php

namespace Wexample\SymfonyDataSync\Interface;

use Wexample\SymfonyDataSync\Class\RemoteItem;

/**
 * A remote able to look items up by a field, which lets a single entity be
 * matched without listing everything (SyncPlanner::planOne).
 */
interface SearchableRemoteAdapterInterface extends RemoteAdapterInterface
{
    /**
     * Compare as the remote does (emails are usually case-insensitive): an item
     * missed here is an item planOne() may create a second time.
     *
     * @return iterable<RemoteItem> the items whose field holds the value, as the remote compares it
     */
    public function findBy(string $field, mixed $value): iterable;
}
