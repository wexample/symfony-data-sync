<?php

namespace Wexample\SymfonyDataSync\Service;

use LogicException;
use Psr\Container\ContainerInterface;
use Wexample\SymfonyDataSync\Class\FieldMapping;
use Wexample\SymfonyDataSync\Class\MatchRule\ExactFieldRule;
use Wexample\SymfonyDataSync\Class\MatchRule\FuzzyFieldRule;
use Wexample\SymfonyDataSync\Class\Predicate;
use Wexample\SymfonyDataSync\Class\SyncDefinition;
use Wexample\SymfonyDataSync\Class\Thresholds;
use Wexample\SymfonyDataSync\DependencyInjection\Configuration;
use Wexample\SymfonyDataSync\Enum\ConflictPolicy;
use Wexample\SymfonyDataSync\Enum\ExcludedLocalPolicy;
use Wexample\SymfonyDataSync\Enum\FieldDirection;
use Wexample\SymfonyDataSync\Enum\MatchNormalizer;
use Wexample\SymfonyDataSync\Enum\OrphanLocalPolicy;
use Wexample\SymfonyDataSync\Enum\OrphanRemotePolicy;
use Wexample\SymfonyDataSync\Enum\PredicateOperator;
use Wexample\SymfonyDataSync\Interface\LocalStoreInterface;
use Wexample\SymfonyDataSync\Interface\RemoteAdapterInterface;

/**
 * The definitions declared under wexample_symfony_data_sync.definitions,
 * built on first use.
 */
class SyncDefinitionRegistry
{
    /**
     * @var array<string, SyncDefinition>
     */
    private array $built = [];

    /**
     * @param array<string, array<string, mixed>> $definitions as processed by Configuration
     */
    public function __construct(
        private readonly DoctrineLinkStore $doctrineLinkStore,
        private readonly array $definitions = [],
        private readonly ?ContainerInterface $services = null,
    ) {
    }

    /**
     * @return string[]
     */
    public function getKeys(): array
    {
        return array_keys($this->definitions);
    }

    public function get(string $key): SyncDefinition
    {
        if (! isset($this->definitions[$key])) {
            throw new LogicException(sprintf(
                'No sync definition "%s". Declared: %s.',
                $key,
                implode(', ', $this->getKeys()) ?: 'none'
            ));
        }

        return $this->built[$key] ??= $this->build($key, $this->definitions[$key]);
    }

    /**
     * @return array<string, SyncDefinition>
     */
    public function all(): array
    {
        $all = [];
        foreach ($this->getKeys() as $key) {
            $all[$key] = $this->get($key);
        }

        return $all;
    }

    private function build(string $key, array $config): SyncDefinition
    {
        $adapter = $this->services->get($config['adapter']);
        if (! $adapter instanceof RemoteAdapterInterface) {
            throw new LogicException(sprintf('The adapter of "%s" must implement %s.', $key, RemoteAdapterInterface::class));
        }

        /** @var LocalStoreInterface $localStore */
        $localStore = $this->services->get($config['local_store'] ?? DoctrineLocalStore::class);

        return new SyncDefinition(
            key: $key,
            localClass: $config['local'],
            adapter: $adapter,
            localStore: $localStore,
            linkStore: $config['link_property']
                ? new PropertyLinkStore($localStore, $config['link_property'])
                : $this->doctrineLinkStore,
            matchRules: array_map($this->buildRule(...), $config['match']),
            fields: array_map(
                static fn (string $local, array $field): FieldMapping => new FieldMapping($local, $field['remote'], FieldDirection::from($field['direction'])),
                array_keys($config['fields']),
                $config['fields'],
            ),
            localFilter: $config['local_filter'],
            localExclude: array_map($this->buildPredicate(...), $config['local_exclude']),
            remoteExclude: array_map($this->buildPredicate(...), $config['remote_exclude']),
            orphanRemote: OrphanRemotePolicy::from($config['orphans']['remote']),
            orphanLocal: OrphanLocalPolicy::from($config['orphans']['local']),
            excludedLocal: ExcludedLocalPolicy::from($config['excluded_local']),
            conflict: ConflictPolicy::from($config['conflict']),
            thresholds: new Thresholds($config['thresholds']['auto_link'], $config['thresholds']['candidate']),
        );
    }

    private function buildRule(array $rule): ExactFieldRule|FuzzyFieldRule
    {
        $normalizers = array_map(static fn (string $name): MatchNormalizer => MatchNormalizer::from($name), $rule['normalize']);

        if (Configuration::MATCH_FUZZY === $rule['type']) {
            // YAML keeps "weight: 2" an int: the rule works on floats.
            $fields = array_map(static fn (array $field): array => ['weight' => (float) $field['weight']] + $field, $rule['fields']);

            return [] === $normalizers
                ? new FuzzyFieldRule($fields)
                : new FuzzyFieldRule($fields, $normalizers);
        }

        return new ExactFieldRule($rule['local'], $rule['remote'], $normalizers, $rule['prefix']);
    }

    private function buildPredicate(array $predicate): Predicate
    {
        return new Predicate($predicate['field'], PredicateOperator::from($predicate['operator']), $predicate['value']);
    }
}
