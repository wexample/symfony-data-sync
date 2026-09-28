<?php

namespace Wexample\SymfonyDataSync\Class;

use Wexample\SymfonyDataSync\Enum\SyncOperation;
use Wexample\SymfonyDataSync\Enum\SyncSide;

/**
 * One decision of a plan: what happens to a local entity, a remote item, or
 * the pair of them, and why.
 */
final readonly class SyncRelation
{
    /**
     * @param FieldDiff[] $diffs for updates and conflicts. An update applies all of them, pushing and
     *                           pulling at once: the fields are disjoint, so nothing is lost
     * @param array<string, mixed> $context whatever explains the decision (competing ids, score…)
     */
    public function __construct(
        public string $definitionKey,
        public SyncOperation $operation,
        public string $reason,
        public ?LocalItem $local = null,
        public ?RemoteItem $remote = null,
        public ?LinkRecord $link = null,
        public array $diffs = [],
        public array $context = [],
    ) {
    }

    public function withOperation(SyncOperation $operation, string $reason): self
    {
        return new self($this->definitionKey, $operation, $reason, $this->local, $this->remote, $this->link, $this->diffs, $this->context);
    }

    /**
     * @return FieldDiff[]
     */
    public function getDiffsFor(SyncSide $target): array
    {
        return array_values(array_filter($this->diffs, static fn (FieldDiff $diff): bool => $diff->target === $target));
    }
}
