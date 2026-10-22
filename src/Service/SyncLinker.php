<?php

namespace Wexample\SymfonyDataSync\Service;

use InvalidArgumentException;

/**
 * Links a local entity and a remote item by hand, after checking both exist
 * and neither is linked yet: how a candidate or a conflict gets resolved,
 * from the console or a screen.
 */
class SyncLinker
{
    public function __construct(
        private readonly SyncDefinitionRegistry $registry,
    ) {
    }

    public function link(string $definitionKey, string $localId, string $remoteId): void
    {
        $definition = $this->registry->get($definitionKey);

        $definition->localStore->find($definition, $localId) ?? throw new InvalidArgumentException(sprintf('No local entity "%s".', $localId));
        $definition->adapter->get($remoteId) ?? throw new InvalidArgumentException(sprintf('No remote item "%s".', $remoteId));

        if ($link = $definition->linkStore->findByLocal($definition, $localId)) {
            throw new InvalidArgumentException(sprintf('"%s" is already linked to "%s": unlink it first.', $localId, $link->remoteId));
        }

        if ($link = $definition->linkStore->findByRemote($definition, $remoteId)) {
            throw new InvalidArgumentException(sprintf('"%s" is already linked to "%s": unlink it first.', $remoteId, $link->localId));
        }

        $definition->linkStore->link($definition, $localId, $remoteId);
    }
}
