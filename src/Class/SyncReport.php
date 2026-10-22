<?php

namespace Wexample\SymfonyDataSync\Class;

use DateTimeInterface;
use Wexample\SymfonyDataSync\Enum\SyncOutcome;

/**
 * What a run planned and, unless it was dry, what came of it. toArray() is
 * the contract the console JSON and symfony-data-sync-ds read: keep it stable.
 */
final readonly class SyncReport
{
    /**
     * @param RelationOutcome[] $outcomes in plan order
     */
    public function __construct(
        public array $outcomes,
        public bool $dryRun,
    ) {
    }

    public function hasErrors(): bool
    {
        foreach ($this->outcomes as $outcome) {
            if (SyncOutcome::Error === $outcome->outcome) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, int>
     */
    public function countByOperation(): array
    {
        return (new SyncPlan(array_map(static fn (RelationOutcome $outcome): SyncRelation => $outcome->relation, $this->outcomes)))->countByOperation();
    }

    /**
     * @return array<string, int> by outcome value, in the enum's order, zeros left out
     */
    public function countByOutcome(): array
    {
        $counts = [];
        foreach (SyncOutcome::cases() as $case) {
            $count = count(array_filter($this->outcomes, static fn (RelationOutcome $outcome): bool => $outcome->outcome === $case));

            if ($count > 0) {
                $counts[$case->value] = $count;
            }
        }

        return $counts;
    }

    /**
     * @return array{dryRun: bool, counts: array<string, int>, outcomes: array<string, int>, relations: array<int, array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'dryRun' => $this->dryRun,
            'counts' => $this->countByOperation(),
            'outcomes' => $this->countByOutcome(),
            'relations' => array_map($this->relationToArray(...), $this->outcomes),
        ];
    }

    /**
     * One line per relation, for a console table.
     *
     * @return array<int, array<int, string>>
     */
    public function toRows(): array
    {
        return array_map(static fn (RelationOutcome $outcome): array => [
            $outcome->relation->definitionKey,
            $outcome->relation->operation->value,
            $outcome->relation->local?->id ?? '',
            $outcome->relation->remote?->id ?? $outcome->relation->link?->remoteId ?? '',
            $outcome->relation->reason,
            $outcome->outcome->value.($outcome->message ? ': '.$outcome->message : ''),
        ], $this->outcomes);
    }

    private function relationToArray(RelationOutcome $outcome): array
    {
        $relation = $outcome->relation;

        return [
            'definition' => $relation->definitionKey,
            'operation' => $relation->operation->value,
            'side' => $relation->operation->side()->value,
            'reason' => $relation->reason,
            'outcome' => $outcome->outcome->value,
            'message' => $outcome->message,
            'local' => $relation->local ? ['id' => $relation->local->id, 'fields' => self::scalars($relation->local->fields)] : null,
            'remote' => $relation->remote ? ['id' => $relation->remote->id, 'fields' => self::scalars($relation->remote->fields)] : null,
            'link' => $relation->link ? ['localId' => $relation->link->localId, 'remoteId' => $relation->link->remoteId] : null,
            'diffs' => array_map(static fn (FieldDiff $diff): array => [
                'localField' => $diff->localField,
                'remoteField' => $diff->remoteField,
                'localValue' => self::scalar($diff->localValue),
                'remoteValue' => self::scalar($diff->remoteValue),
                'target' => $diff->target->value,
            ], $relation->diffs),
            'context' => $relation->context,
        ];
    }

    private static function scalars(array $fields): array
    {
        return array_map(self::scalar(...), $fields);
    }

    private static function scalar(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            is_object($value) => method_exists($value, '__toString') ? (string) $value : $value::class,
            default => $value,
        };
    }
}
