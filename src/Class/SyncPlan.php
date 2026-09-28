<?php

namespace Wexample\SymfonyDataSync\Class;

use Wexample\SymfonyDataSync\Enum\SyncOperation;

/**
 * Every decision of a run, before anything is written: what a dry run shows.
 */
final readonly class SyncPlan
{
    /**
     * @param SyncRelation[] $relations
     */
    public function __construct(
        public array $relations = [],
    ) {
    }

    /**
     * @param SyncOperation[] $operations
     */
    public function only(array $operations): self
    {
        return new self(array_values(array_filter(
            $this->relations,
            static fn (SyncRelation $relation): bool => in_array($relation->operation, $operations, true)
        )));
    }

    public function withoutUpToDate(): self
    {
        return new self(array_values(array_filter(
            $this->relations,
            static fn (SyncRelation $relation): bool => SyncOperation::UpToDate !== $relation->operation
        )));
    }

    public function merge(self $plan): self
    {
        return new self([...$this->relations, ...$plan->relations]);
    }

    /**
     * @return array<string, int> by operation value, in the enum's order, zeros left out
     */
    public function countByOperation(): array
    {
        $counts = [];
        foreach (SyncOperation::cases() as $operation) {
            $count = count(array_filter($this->relations, static fn (SyncRelation $relation): bool => $relation->operation === $operation));

            if ($count > 0) {
                $counts[$operation->value] = $count;
            }
        }

        return $counts;
    }
}
