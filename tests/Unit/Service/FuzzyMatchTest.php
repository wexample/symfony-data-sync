<?php

namespace Wexample\SymfonyDataSync\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use stdClass;
use Wexample\SymfonyDataSync\Class\LocalItem;
use Wexample\SymfonyDataSync\Class\MatchRule\FuzzyFieldRule;
use Wexample\SymfonyDataSync\Class\RemoteItem;
use Wexample\SymfonyDataSync\Class\SyncDefinition;
use Wexample\SymfonyDataSync\Class\Thresholds;
use Wexample\SymfonyDataSync\Service\Matcher;
use Wexample\SymfonyDataSync\Testing\InMemoryLinkStore;
use Wexample\SymfonyDataSync\Testing\InMemoryLocalStore;
use Wexample\SymfonyDataSync\Testing\InMemoryRemoteAdapter;

class FuzzyMatchTest extends TestCase
{
    public function testANearlyIdenticalNameIsLinked(): void
    {
        $result = $this->match('Association des Jardins', ['r1' => 'Association des jardins ']);

        $this->assertSame('r1', $result->matches['l1']['remoteId']);
        $this->assertGreaterThanOrEqual(0.95, $result->matches['l1']['score']);
    }

    public function testASimilarNameIsProposed(): void
    {
        $result = $this->match('Association des Jardins', ['r1' => 'Assoc. des Jardins']);

        $this->assertSame([], $result->matches);
        $this->assertSame('r1', $result->candidates[0]['remoteId']);
    }

    public function testADifferentNameIsIgnored(): void
    {
        $result = $this->match('Association des Jardins', ['r1' => 'Club de Tennis']);

        $this->assertSame([], $result->matches);
        $this->assertSame([], $result->candidates);
    }

    public function testTwoEquallyCloseItemsAreAConflict(): void
    {
        $result = $this->match('Jardins', ['r1' => 'Jardins', 'r2' => 'jardins']);

        $this->assertSame([], $result->matches);
        $this->assertSame(['r1', 'r2'], $result->conflicts[0]['remoteIds']);
    }

    public function testWeightsTiltTheScore(): void
    {
        $rule = new FuzzyFieldRule([
            ['local' => 'name', 'remote' => 'name', 'weight' => 3.0],
            ['local' => 'city', 'remote' => 'city', 'weight' => 1.0],
        ]);

        $score = $rule->score(
            new LocalItem('l1', ['name' => 'Jardins', 'city' => 'Lyon'], new stdClass()),
            new RemoteItem('r1', ['name' => 'Jardins', 'city' => 'Paris']),
        );

        $this->assertEqualsWithDelta(0.75, $score, 0.01);
    }

    /**
     * @param array<string, string> $remoteNames
     */
    private function match(string $localName, array $remoteNames): \Wexample\SymfonyDataSync\Class\MatchResult
    {
        $definition = new SyncDefinition(
            'orgs',
            stdClass::class,
            new InMemoryRemoteAdapter(),
            new InMemoryLocalStore(),
            new InMemoryLinkStore(),
            matchRules: [new FuzzyFieldRule([['local' => 'name', 'remote' => 'name', 'weight' => 1.0]])],
            thresholds: new Thresholds(0.95, 0.7),
        );

        return (new Matcher())->match(
            $definition,
            [new LocalItem('l1', ['name' => $localName], new stdClass())],
            array_map(static fn (string $id, string $name): RemoteItem => new RemoteItem($id, ['name' => $name]), array_keys($remoteNames), $remoteNames),
        );
    }
}
