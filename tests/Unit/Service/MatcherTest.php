<?php

namespace Wexample\SymfonyDataSync\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use stdClass;
use Wexample\SymfonyDataSync\Class\LocalItem;
use Wexample\SymfonyDataSync\Class\MatchRule\ExactFieldRule;
use Wexample\SymfonyDataSync\Class\RemoteItem;
use Wexample\SymfonyDataSync\Class\SyncDefinition;
use Wexample\SymfonyDataSync\Enum\MatchNormalizer;
use Wexample\SymfonyDataSync\Service\Matcher;
use Wexample\SymfonyDataSync\Testing\InMemoryLinkStore;
use Wexample\SymfonyDataSync\Testing\InMemoryLocalStore;
use Wexample\SymfonyDataSync\Testing\InMemoryRemoteAdapter;

class MatcherTest extends TestCase
{
    public function testAnEarlierRuleWinsWhateverTheItemsOrder(): void
    {
        // The legacy bug: remote A shares the username, remote B the email,
        // and A came first in the listing. Email is the stronger rule.
        $result = (new Matcher())->match(
            $this->definition(),
            [$this->local('u1', 'ada@example.test', 'ada')],
            [$this->remote('A', 'other@example.test', 'ada'), $this->remote('B', 'ada@example.test', 'ada_l')],
        );

        $this->assertSame('B', $result->matches['u1']['remoteId']);
        $this->assertSame('email = email', $result->matches['u1']['rule']);
    }

    public function testEmailsMatchWhateverTheirCase(): void
    {
        $result = (new Matcher())->match(
            $this->definition(),
            [$this->local('u1', ' Ada@Example.test', 'ada')],
            [$this->remote('A', 'ada@example.TEST', 'someone')],
        );

        $this->assertSame('A', $result->matches['u1']['remoteId']);
    }

    public function testASharedValueIsAConflictNotAGuess(): void
    {
        $result = (new Matcher())->match(
            $this->definition(),
            [$this->local('u1', 'team@example.test', 'ada'), $this->local('u2', 'team@example.test', 'bob')],
            [$this->remote('A', 'team@example.test', 'ada')],
        );

        $this->assertSame([], $result->matches);
        $this->assertSame([['localIds' => ['u1', 'u2'], 'remoteIds' => ['A'], 'rule' => 'email = email']], $result->conflicts);
    }

    public function testAConflictedItemIsNotRetriedByAWeakerRule(): void
    {
        // Ambiguous on email: the username rule must not then pair u1 and A.
        $result = (new Matcher())->match(
            $this->definition(),
            [$this->local('u1', 'team@example.test', 'ada'), $this->local('u2', 'team@example.test', 'bob')],
            [$this->remote('A', 'team@example.test', 'ada')],
        );

        $this->assertArrayNotHasKey('u1', $result->matches);
    }

    public function testEmptyValuesNeverMatch(): void
    {
        $result = (new Matcher())->match(
            $this->definition(),
            [$this->local('u1', '', 'ada')],
            [$this->remote('A', '', 'bob')],
        );

        $this->assertSame([], $result->matches);
        $this->assertSame([], $result->conflicts);
    }

    public function testAPrefixMatchesGroupsNamedAfterEntities(): void
    {
        $definition = new SyncDefinition(
            'projects',
            stdClass::class,
            new InMemoryRemoteAdapter(),
            new InMemoryLocalStore(),
            new InMemoryLinkStore(),
            matchRules: [new ExactFieldRule('name', 'name', [MatchNormalizer::Slug], 'projet-')],
        );

        $result = (new Matcher())->match(
            $definition,
            [new LocalItem('p1', ['name' => 'Été Solaire'], new stdClass())],
            [new RemoteItem('g1', ['name' => 'projet-ete-solaire'])],
        );

        $this->assertSame('g1', $result->matches['p1']['remoteId']);
    }

    private function definition(): SyncDefinition
    {
        return new SyncDefinition(
            'users',
            stdClass::class,
            new InMemoryRemoteAdapter(),
            new InMemoryLocalStore(),
            new InMemoryLinkStore(),
            matchRules: [
                new ExactFieldRule('email', 'email', [MatchNormalizer::Email]),
                new ExactFieldRule('username', 'username', [MatchNormalizer::Lower]),
            ],
        );
    }

    private function local(string $id, string $email, string $username): LocalItem
    {
        return new LocalItem($id, ['email' => $email, 'username' => $username], new stdClass());
    }

    private function remote(string $id, string $email, string $username): RemoteItem
    {
        return new RemoteItem($id, ['email' => $email, 'username' => $username]);
    }
}
