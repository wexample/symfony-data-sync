<?php

namespace Wexample\SymfonyDataSync\Interface;

use Wexample\SymfonyDataSync\Class\RemoteItem;

/**
 * A remote able to deactivate an item without deleting it, keeping its
 * history: what excluded_local: disable_remote needs.
 */
interface DisablableRemoteAdapterInterface extends RemoteAdapterInterface
{
    public function disable(string $id): void;

    /**
     * Whether the item is already disabled, so the planner does not ask again
     * on every run.
     */
    public function isDisabled(RemoteItem $item): bool;
}
