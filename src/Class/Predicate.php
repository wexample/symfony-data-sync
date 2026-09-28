<?php

namespace Wexample\SymfonyDataSync\Class;

use Wexample\SymfonyDataSync\Enum\PredicateOperator;

/**
 * A test on one field of an item, as declared in a definition's exclusions:
 * `{ field: roles, operator: contains, value: bot }`.
 */
final readonly class Predicate
{
    public function __construct(
        public string $field,
        public PredicateOperator $operator,
        public mixed $value = null,
    ) {
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function matches(array $fields): bool
    {
        $actual = $fields[$this->field] ?? null;

        return match ($this->operator) {
            PredicateOperator::Equals => $actual == $this->value,
            PredicateOperator::NotEquals => $actual != $this->value,
            PredicateOperator::In => in_array($actual, (array) $this->value, false),
            PredicateOperator::Contains => is_array($actual)
                ? in_array($this->value, $actual, false)
                : is_string($actual) && str_contains($actual, (string) $this->value),
            PredicateOperator::Empty => null === $actual || '' === $actual || [] === $actual,
        };
    }
}
