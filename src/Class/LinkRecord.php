<?php

namespace Wexample\SymfonyDataSync\Class;

use DateTimeImmutable;

/**
 * A stored link between a local entity and a remote item, as a link store
 * reports it.
 */
final readonly class LinkRecord
{
    /**
     * @param string|null $lastSyncedHash hash of the mapped values both sides agreed on at the last sync
     */
    public function __construct(
        public string $localId,
        public string $remoteId,
        public ?string $lastSyncedHash = null,
        public ?DateTimeImmutable $lastSyncedAt = null,
        public DateTimeImmutable $createdAt = new DateTimeImmutable(),
    ) {
    }
}
