<?php

namespace Wexample\SymfonyDataSync\Class;

use Wexample\SymfonyDataSync\Enum\ConflictPolicy;
use Wexample\SymfonyDataSync\Enum\ExcludedLocalPolicy;
use Wexample\SymfonyDataSync\Enum\OrphanLocalPolicy;
use Wexample\SymfonyDataSync\Enum\OrphanRemotePolicy;
use Wexample\SymfonyDataSync\Interface\LinkStoreInterface;
use Wexample\SymfonyDataSync\Interface\MatchRuleInterface;
use Wexample\SymfonyDataSync\Interface\RemoteAdapterInterface;

/**
 * How one local class is kept in step with one remote. Defaults never destroy
 * nor create anything: they report.
 */
final readonly class SyncDefinition
{
    /**
     * @param class-string $localClass
     * @param MatchRuleInterface[] $matchRules tried in order after stored links
     * @param FieldMapping[] $fields
     * @param array<string, mixed> $localFilter Doctrine criteria selecting the covered entities
     * @param Predicate[] $localExclude local entities kept linked but handled per $excludedLocal
     * @param Predicate[] $remoteExclude remote items never touched (bots, protected accounts…)
     */
    public function __construct(
        public string $key,
        public string $localClass,
        public RemoteAdapterInterface $adapter,
        public LinkStoreInterface $linkStore,
        public array $matchRules = [],
        public array $fields = [],
        public array $localFilter = [],
        public array $localExclude = [],
        public array $remoteExclude = [],
        public OrphanRemotePolicy $orphanRemote = OrphanRemotePolicy::Report,
        public OrphanLocalPolicy $orphanLocal = OrphanLocalPolicy::Report,
        public ExcludedLocalPolicy $excludedLocal = ExcludedLocalPolicy::Ignore,
        public ConflictPolicy $conflict = ConflictPolicy::Report,
        public Thresholds $thresholds = new Thresholds(),
    ) {
    }

    /**
     * Every local field the planner reads: mapped, matched on, or tested.
     *
     * @return string[]
     */
    public function getLocalFields(): array
    {
        $fields = [
            ...array_map(static fn (FieldMapping $mapping): string => $mapping->localField, $this->fields),
            ...array_map(static fn (Predicate $predicate): string => $predicate->field, $this->localExclude),
        ];

        foreach ($this->matchRules as $rule) {
            $fields = [...$fields, ...$rule->getLocalFields()];
        }

        return array_values(array_unique($fields));
    }

    public function isLocalExcluded(LocalItem $item): bool
    {
        return $this->anyMatches($this->localExclude, $item->fields);
    }

    public function isRemoteExcluded(RemoteItem $item): bool
    {
        return $this->anyMatches($this->remoteExclude, $item->fields);
    }

    /**
     * @param Predicate[] $predicates
     */
    private function anyMatches(array $predicates, array $fields): bool
    {
        foreach ($predicates as $predicate) {
            if ($predicate->matches($fields)) {
                return true;
            }
        }

        return false;
    }
}
