<?php

namespace Wexample\SymfonyDataSync\Class\MatchRule;

use Wexample\SymfonyDataSync\Class\LocalItem;
use Wexample\SymfonyDataSync\Class\RemoteItem;
use Wexample\SymfonyDataSync\Enum\MatchNormalizer;
use Wexample\SymfonyDataSync\Interface\MatchRuleInterface;

/**
 * Same normalized value on both sides. An empty value never matches: two
 * items without an email are not the same person.
 */
final readonly class ExactFieldRule implements MatchRuleInterface
{
    /**
     * @param MatchNormalizer[] $normalizers applied in order
     * @param string $prefix prepended to the local value, e.g. "projet-" for groups named after projects
     */
    public function __construct(
        public string $localField,
        public string $remoteField,
        public array $normalizers = [],
        public string $prefix = '',
    ) {
    }

    public function score(LocalItem $local, RemoteItem $remote): float
    {
        $key = $this->localKey($local);

        return null !== $key && $key === $this->remoteKey($remote) ? 1.0 : 0.0;
    }

    public function localKey(LocalItem $local): ?string
    {
        return $this->normalize($local->get($this->localField), $this->prefix);
    }

    public function remoteKey(RemoteItem $remote): ?string
    {
        return $this->normalize($remote->get($this->remoteField));
    }

    public function getLocalFields(): array
    {
        return [$this->localField];
    }

    public function describe(): string
    {
        return sprintf('%s = %s', $this->localField, $this->remoteField);
    }

    private function normalize(mixed $value, string $prefix = ''): ?string
    {
        if (null === $value || '' === $value || is_array($value)) {
            return null;
        }

        $value = $prefix.$value;
        foreach ($this->normalizers as $normalizer) {
            $value = $normalizer->apply($value);
        }

        return '' === $value ? null : $value;
    }
}
