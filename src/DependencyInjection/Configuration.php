<?php

namespace Wexample\SymfonyDataSync\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Wexample\SymfonyDataSync\Enum\ConflictPolicy;
use Wexample\SymfonyDataSync\Enum\ExcludedLocalPolicy;
use Wexample\SymfonyDataSync\Enum\FieldDirection;
use Wexample\SymfonyDataSync\Enum\MatchNormalizer;
use Wexample\SymfonyDataSync\Enum\OrphanLocalPolicy;
use Wexample\SymfonyDataSync\Enum\OrphanRemotePolicy;
use Wexample\SymfonyDataSync\Enum\PredicateOperator;

class Configuration implements ConfigurationInterface
{
    public const string MATCH_EXACT = 'exact';

    public const string MATCH_FUZZY = 'fuzzy';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('wexample_symfony_data_sync');

        $definition = $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('definitions')
                    ->info('How each local class is kept in step with each remote, by key.')
                    ->useAttributeAsKey('key')
                    ->arrayPrototype();

        $definition
            ->children()
                ->scalarNode('local')->isRequired()->cannotBeEmpty()->info('The local entity class.')->end()
                ->scalarNode('adapter')->isRequired()->cannotBeEmpty()->info('Service id of the RemoteAdapterInterface.')->end()
                ->scalarNode('local_store')->defaultNull()->info('Service id of a LocalStoreInterface; Doctrine by default.')->end()
                ->scalarNode('link_property')->defaultNull()->info('Keep the remote id in this entity property instead of the sync_link table.')->end()
                ->variableNode('local_filter')->defaultValue([])->info('Doctrine criteria selecting the covered entities.')->end()
                ->arrayNode('orphans')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('remote')->values($this->values(OrphanRemotePolicy::cases()))->defaultValue(OrphanRemotePolicy::Report->value)->end()
                        ->enumNode('local')->values($this->values(OrphanLocalPolicy::cases()))->defaultValue(OrphanLocalPolicy::Report->value)->end()
                    ->end()
                ->end()
                ->enumNode('excluded_local')->values($this->values(ExcludedLocalPolicy::cases()))->defaultValue(ExcludedLocalPolicy::Ignore->value)->end()
                ->enumNode('conflict')->values($this->values(ConflictPolicy::cases()))->defaultValue(ConflictPolicy::Report->value)->end()
                ->arrayNode('thresholds')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->floatNode('auto_link')->defaultValue(0.95)->min(0)->max(1)->end()
                        ->floatNode('candidate')->defaultValue(0.7)->min(0)->max(1)->end()
                    ->end()
                ->end()
            ->end();

        $this->addFields($definition);
        $this->addMatch($definition);
        $this->addPredicates($definition, 'local_exclude', 'Local entities kept linked but handled per excluded_local.');
        $this->addPredicates($definition, 'remote_exclude', 'Remote items never touched (bots, protected accounts…).');

        return $treeBuilder;
    }

    private function addFields(ArrayNodeDefinition $definition): void
    {
        $definition
            ->children()
                ->arrayNode('fields')
                    ->info('Local field => remote field, or => { remote, direction }.')
                    ->useAttributeAsKey('local')
                    ->arrayPrototype()
                        ->beforeNormalization()
                            ->ifString()
                            ->then(static fn (string $remote): array => ['remote' => $remote])
                        ->end()
                        ->children()
                            ->scalarNode('remote')->isRequired()->end()
                            ->enumNode('direction')->values($this->values(FieldDirection::cases()))->defaultValue(FieldDirection::LocalToRemote->value)->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    private function addMatch(ArrayNodeDefinition $definition): void
    {
        $definition
            ->children()
                ->arrayNode('match')
                    ->info('Rules tried in order after stored links: exact on one field, or fuzzy over weighted fields.')
                    ->arrayPrototype()
                        ->children()
                            ->enumNode('type')->values([self::MATCH_EXACT, self::MATCH_FUZZY])->defaultValue(self::MATCH_EXACT)->end()
                            ->scalarNode('local')->defaultNull()->end()
                            ->scalarNode('remote')->defaultNull()->end()
                            ->scalarNode('prefix')->defaultValue('')->info('Prepended to the local value, e.g. "project-".')->end()
                            ->arrayNode('normalize')
                                ->enumPrototype()->values($this->values(MatchNormalizer::cases()))->end()
                            ->end()
                            ->arrayNode('fields')
                                ->info('For fuzzy rules.')
                                ->arrayPrototype()
                                    ->children()
                                        ->scalarNode('local')->isRequired()->end()
                                        ->scalarNode('remote')->isRequired()->end()
                                        ->floatNode('weight')->defaultValue(1.0)->min(0)->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->validate()
                            ->ifTrue(static fn (array $rule): bool => self::MATCH_EXACT === $rule['type'] && (null === $rule['local'] || null === $rule['remote']))
                            ->thenInvalid('An exact rule needs "local" and "remote".')
                        ->end()
                        ->validate()
                            ->ifTrue(static fn (array $rule): bool => self::MATCH_FUZZY === $rule['type'] && [] === $rule['fields'])
                            ->thenInvalid('A fuzzy rule needs "fields".')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    private function addPredicates(ArrayNodeDefinition $definition, string $name, string $info): void
    {
        $definition
            ->children()
                ->arrayNode($name)
                    ->info($info)
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('field')->isRequired()->end()
                            ->enumNode('operator')->values($this->values(PredicateOperator::cases()))->defaultValue(PredicateOperator::Equals->value)->end()
                            ->variableNode('value')->defaultNull()->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param \BackedEnum[] $cases
     *
     * @return string[]
     */
    private function values(array $cases): array
    {
        return array_map(static fn (\BackedEnum $case): string => $case->value, $cases);
    }
}
