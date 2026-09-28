<?php

namespace Wexample\SymfonyDataSync\Interface;

/**
 * A remote able to deactivate an item without deleting it, keeping its
 * history: what excluded_local: disable_remote needs.
 */
interface DisablableRemoteAdapterInterface extends RemoteAdapterInterface
{
    public function disable(string $id): void;
}
