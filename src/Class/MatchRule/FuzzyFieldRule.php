<?php

namespace Wexample\SymfonyDataSync\Class\MatchRule;

use Wexample\SymfonyDataSync\Class\LocalItem;
use Wexample\SymfonyDataSync\Class\RemoteItem;
use Wexample\SymfonyDataSync\Enum\MatchNormalizer;
use Wexample\SymfonyDataSync\Interface\MatchRuleInterface;

/**
 * How alike two items look: the Levenshtein ratio of each field pair (1 when
 * equal, 0 when nothing is shared), averaged by weight. What the definition's
 * thresholds do with the score is the matcher's call.
 */
final readonly class FuzzyFieldRule implements MatchRuleInterface
{
    /**
     * @param array<int, array{local: string, remote: string, weight: float}> $fields
     * @param MatchNormalizer[] $normalizers applied to every value before comparing
     */
    public function __construct(
        public array $fields,
        public array $normalizers = [MatchNormalizer::Trim, MatchNormalizer::Lower],
    ) {
    }

    public function score(LocalItem $local, RemoteItem $remote): float
    {
        $total = 0.0;
        $weights = 0.0;

        foreach ($this->fields as $field) {
            $total += $field['weight'] * $this->ratio(
                $this->normalize($local->get($field['local'])),
                $this->normalize($remote->get($field['remote'])),
            );
            $weights += $field['weight'];
        }

        return $weights > 0 ? $total / $weights : 0.0;
    }

    public function getLocalFields(): array
    {
        return array_column($this->fields, 'local');
    }

    public function describe(): string
    {
        return 'looks like '.implode(', ', array_map(
            static fn (array $field): string => $field['local'].' ~ '.$field['remote'],
            $this->fields
        ));
    }

    private function ratio(string $a, string $b): float
    {
        if ('' === $a || '' === $b) {
            return 0.0;
        }

        return 1 - levenshtein($a, $b) / max(mb_strlen($a), mb_strlen($b));
    }

    private function normalize(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        foreach ($this->normalizers as $normalizer) {
            $value = $normalizer->apply($value);
        }

        return $value;
    }
}
