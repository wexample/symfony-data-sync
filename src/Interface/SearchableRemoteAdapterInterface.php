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
     * @return iterable<RemoteItem> the items whose field holds the value, as the remote compares it
     */
    public function findBy(string $field, mixed $value): iterable;
}
