<?php

namespace Wexample\SymfonyDataSync\Tests\Unit\Service;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use stdClass;
use Wexample\SymfonyDataSync\Class\FieldMapping;
use Wexample\SymfonyDataSync\Class\LinkRecord;
use Wexample\SymfonyDataSync\Class\MatchRule\ExactFieldRule;
use Wexample\SymfonyDataSync\Class\Predicate;
use Wexample\SymfonyDataSync\Class\SyncDefinition;
use Wexample\SymfonyDataSync\Class\SyncPlan;
use Wexample\SymfonyDataSync\Class\SyncRelation;
use Wexample\SymfonyDataSync\Enum\ConflictPolicy;
use Wexample\SymfonyDataSync\Enum\ExcludedLocalPolicy;
use Wexample\SymfonyDataSync\Enum\FieldDirection;
use Wexample\SymfonyDataSync\Enum\MatchNormalizer;
use Wexample\SymfonyDataSync\Enum\OrphanLocalPolicy;
use Wexample\SymfonyDataSync\Enum\OrphanRemotePolicy;
use Wexample\SymfonyDataSync\Enum\PredicateOperator;
use Wexample\SymfonyDataSync\Enum\SyncOperation;
use Wexample\SymfonyDataSync\Enum\SyncSide;
use Wexample\SymfonyDataSync\Helper\SyncValueHelper;
use Wexample\SymfonyDataSync\Service\Matcher;
use Wexample\SymfonyDataSync\Service\SyncPlanner;
use Wexample\SymfonyDataSync\Testing\InMemoryLinkStore;
use Wexample\SymfonyDataSync\Testing\InMemoryLocalStore;
use Wexample\SymfonyDataSync\Testing\InMemoryRemoteAdapter;

class SyncPlannerTest extends TestCase
{
    private InMemoryLocalStore $locals;

    private InMemoryLinkStore $links;

    protected function setUp(): void
    {
        $this->locals = new InMemoryLocalStore([
            'u1' => ['username' => 'ada', 'email' => 'ada@example.test', 'enabled' => true],
        ]);
        $this->links = new InMemoryLinkStore();
    }

    public function testAnUnmatchedLocalIsCreatedRemotelyWhenThePolicySaysSo(): void
    {
        $plan = $this->plan(new InMemoryRemoteAdapter(), orphanLocal: OrphanLocalPolicy::CreateRemote);

        $this->assertOperations([SyncOperation::RemoteCreate], $plan);
    }

    public function testAnUnmatchedLocalIsOnlyReportedByDefault(): void
    {
        $this->assertOperations([SyncOperation::Unmatched], $this->plan(new InMemoryRemoteAdapter()));
    }

    public function testAnOrphanRemoteIsCreatedLocallyWhenThePolicySaysSo(): void
    {
        $this->locals = new InMemoryLocalStore();
        $plan = $this->plan(new InMemoryRemoteAdapter(['r1' => ['username' => 'bob', 'email' => 'bob@example.test']]), orphanRemote: OrphanRemotePolicy::CreateLocal);

        $this->assertOperations([SyncOperation::LocalCreate], $plan);
    }

    public function testAnUnlinkedPairIsLinkedByEmail(): void
    {
        $plan = $this->plan(new InMemoryRemoteAdapter(['r1' => ['username' => 'ada_l', 'email' => 'ADA@example.test']]));

        $this->assertOperations([SyncOperation::LocalLink], $plan);
        $this->assertSame('r1', $plan->relations[0]->remote->id);
        $this->assertSame('email = email', $plan->relations[0]->context['rule']);
    }

    public function testAStaleLinkIsUnlinkedAndNotRematchedInTheSameRun(): void
    {
        $this->links->seed('users', new LinkRecord('u1', 'gone'));
        $plan = $this->plan(new InMemoryRemoteAdapter(['r1' => ['username' => 'ada', 'email' => 'ada@example.test']]));

        $this->assertOperations([SyncOperation::LocalUnlink, SyncOperation::Unmatched], $plan);
        $this->assertSame('The remote item is gone.', $plan->relations[0]->reason);
    }

    public function testDuplicateLinksKeepTheOldestOnly(): void
    {
        $this->locals = new InMemoryLocalStore([
            'u1' => ['username' => 'ada', 'email' => 'ada@example.test'],
            'u2' => ['username' => 'ada2', 'email' => 'ada2@example.test'],
        ]);
        $this->links->seed(
            'users',
            new LinkRecord('u2', 'r1', createdAt: new DateTimeImmutable('2024-02-01')),
            new LinkRecord('u1', 'r1', createdAt: new DateTimeImmutable('2024-01-01')),
        );
        $plan = $this->plan(new InMemoryRemoteAdapter(['r1' => ['username' => 'ada', 'email' => 'ada@example.test']]));

        $unlinks = $plan->only([SyncOperation::LocalUnlink])->relations;
        $this->assertCount(1, $unlinks);
        $this->assertSame('u2', $unlinks[0]->link->localId);
        $this->assertCount(1, $plan->only([SyncOperation::UpToDate])->relations);
    }

    public function testALocalOwnedFieldIsPushed(): void
    {
        $this->links->seed('users', new LinkRecord('u1', 'r1'));
        $plan = $this->plan(new InMemoryRemoteAdapter(['r1' => ['username' => 'old', 'email' => 'ada@example.test']]));

        $this->assertOperations([SyncOperation::RemoteUpdate], $plan);
        $this->assertSame(SyncSide::Remote, $plan->relations[0]->diffs[0]->target);
    }

    public function testARemoteOwnedFieldIsPulled(): void
    {
        $this->links->seed('users', new LinkRecord('u1', 'r1'));
        $plan = $this->plan(
            new InMemoryRemoteAdapter(['r1' => ['username' => 'ada', 'email' => 'new@example.test']]),
            emailDirection: FieldDirection::RemoteToLocal,
        );

        $this->assertOperations([SyncOperation::LocalUpdate], $plan);
        $this->assertSame('new@example.test', $plan->relations[0]->getDiffsFor(SyncSide::Local)[0]->remoteValue);
    }

    public function testASharedFieldFollowsTheSideThatChanged(): void
    {
        $agreed = ['username' => 'ada', 'email' => 'ada@example.test'];
        $this->links->seed('users', new LinkRecord('u1', 'r1', $this->hash($agreed)));
        $plan = $this->plan(
            new InMemoryRemoteAdapter(['r1' => ['username' => 'ada', 'email' => 'changed@example.test']]),
            emailDirection: FieldDirection::Both,
        );

        $this->assertOperations([SyncOperation::LocalUpdate], $plan);
    }

    public function testBothSidesChangedIsAConflict(): void
    {
        $this->locals = new InMemoryLocalStore(['u1' => ['username' => 'ada', 'email' => 'local@example.test']]);
        $this->links->seed('users', new LinkRecord('u1', 'r1', $this->hash(['username' => 'ada', 'email' => 'ada@example.test'])));
        $plan = $this->plan(
            new InMemoryRemoteAdapter(['r1' => ['username' => 'ada', 'email' => 'remote@example.test']]),
            emailDirection: FieldDirection::Both,
        );

        $this->assertOperations([SyncOperation::Conflict], $plan);
    }

    public function testTheConflictPolicyCanDecide(): void
    {
        $this->locals = new InMemoryLocalStore(['u1' => ['username' => 'ada', 'email' => 'local@example.test']]);
        $this->links->seed('users', new LinkRecord('u1', 'r1'));
        $plan = $this->plan(
            new InMemoryRemoteAdapter(['r1' => ['username' => 'ada', 'email' => 'remote@example.test']]),
            emailDirection: FieldDirection::Both,
            conflict: ConflictPolicy::LocalWins,
        );

        $this->assertOperations([SyncOperation::RemoteUpdate], $plan);
    }

    public function testAnExcludedLocalFollowsItsPolicy(): void
    {
        $this->locals = new InMemoryLocalStore(['u1' => ['username' => 'ada', 'email' => 'ada@example.test', 'enabled' => false]]);
        $this->links->seed('users', new LinkRecord('u1', 'r1'));
        $remote = ['r1' => ['username' => 'ada', 'email' => 'ada@example.test']];

        $this->assertOperations([], $this->plan(new InMemoryRemoteAdapter($remote)));
        $this->assertOperations([SyncOperation::RemoteDisable], $this->plan(new InMemoryRemoteAdapter($remote), excludedLocal: ExcludedLocalPolicy::DisableRemote));
        $this->assertOperations([SyncOperation::RemoteRemove], $this->plan(new InMemoryRemoteAdapter($remote), excludedLocal: ExcludedLocalPolicy::RemoveRemote));
    }

    public function testProtectedRemotesAreNeverTouched(): void
    {
        $this->locals = new InMemoryLocalStore();
        $plan = $this->plan(new InMemoryRemoteAdapter([
            'bot' => ['username' => 'rocket.cat', 'email' => 'cat@example.test', 'roles' => ['bot']],
            'admin' => ['username' => 'admin', 'email' => 'admin@example.test', 'roles' => ['admin']],
            'noemail' => ['username' => 'ghost', 'email' => '', 'roles' => []],
        ]), orphanRemote: OrphanRemotePolicy::RemoveRemote);

        $this->assertOperations([], $plan);
    }

    public function testTwoRemotesOnOneEntityBothLink(): void
    {
        // The legacy "TODO Fails with two": the second remote created an orphan
        // relation for the person the first had just recovered.
        $remote = ['r1' => ['username' => 'ada', 'email' => 'ada@example.test']];
        $plan = (new SyncPlanner(new Matcher()))->planAll([
            $this->definition(new InMemoryRemoteAdapter($remote), key: 'chat'),
            $this->definition(new InMemoryRemoteAdapter($remote), key: 'cloud'),
        ]);

        $this->assertOperations([SyncOperation::LocalLink, SyncOperation::LocalLink], $plan);
    }

    public function testOneIdentityIsCreatedOnceAcrossDefinitions(): void
    {
        $this->locals = new InMemoryLocalStore();
        $remote = ['r1' => ['username' => 'bob', 'email' => 'bob@example.test']];
        $plan = (new SyncPlanner(new Matcher()))->planAll([
            $this->definition(new InMemoryRemoteAdapter($remote), key: 'chat', orphanRemote: OrphanRemotePolicy::CreateLocal),
            $this->definition(new InMemoryRemoteAdapter($remote), key: 'cloud', orphanRemote: OrphanRemotePolicy::CreateLocal),
        ]);

        $this->assertOperations([SyncOperation::LocalCreate, SyncOperation::Postponed], $plan);
        $this->assertStringContainsString('"chat"', $plan->relations[1]->reason);
    }

    public function testOneEntityIsPlannedWithoutListingTheRemote(): void
    {
        $adapter = new InMemoryRemoteAdapter(['r1' => ['username' => 'x', 'email' => 'ada@example.test']], refuseListing: true);
        $definition = $this->definition($adapter);

        $plan = (new SyncPlanner(new Matcher()))->planOne($definition, $this->locals->find($definition, 'u1'));

        $this->assertOperations([SyncOperation::LocalLink], $plan);
    }

    private function plan(InMemoryRemoteAdapter $adapter, mixed ...$options): SyncPlan
    {
        return (new SyncPlanner(new Matcher()))->plan($this->definition($adapter, ...$options));
    }

    private function definition(
        InMemoryRemoteAdapter $adapter,
        string $key = 'users',
        FieldDirection $emailDirection = FieldDirection::LocalToRemote,
        OrphanLocalPolicy $orphanLocal = OrphanLocalPolicy::Report,
        OrphanRemotePolicy $orphanRemote = OrphanRemotePolicy::Report,
        ExcludedLocalPolicy $excludedLocal = ExcludedLocalPolicy::Ignore,
        ConflictPolicy $conflict = ConflictPolicy::Report,
    ): SyncDefinition {
        return new SyncDefinition(
            $key,
            stdClass::class,
            $adapter,
            $this->locals,
            $key === 'users' ? $this->links : new InMemoryLinkStore(),
            matchRules: [
                new ExactFieldRule('email', 'email', [MatchNormalizer::Email]),
                new ExactFieldRule('username', 'username', [MatchNormalizer::Lower]),
            ],
            fields: [
                new FieldMapping('username', 'username'),
                new FieldMapping('email', 'email', $emailDirection),
            ],
            localExclude: [new Predicate('enabled', PredicateOperator::Equals, false)],
            remoteExclude: [
                new Predicate('roles', PredicateOperator::Contains, 'bot'),
                new Predicate('username', PredicateOperator::In, ['admin']),
                new Predicate('email', PredicateOperator::Empty),
            ],
            orphanRemote: $orphanRemote,
            orphanLocal: $orphanLocal,
            excludedLocal: $excludedLocal,
            conflict: $conflict,
        );
    }

    private function hash(array $fields): string
    {
        return SyncValueHelper::hash([new FieldMapping('username', 'username'), new FieldMapping('email', 'email')], $fields, false);
    }

    /**
     * @param SyncOperation[] $expected
     */
    private function assertOperations(array $expected, SyncPlan $plan): void
    {
        $this->assertSame(
            array_map(static fn (SyncOperation $operation): string => $operation->value, $expected),
            array_map(static fn (SyncRelation $relation): string => $relation->operation->value, $plan->relations)
        );
    }
}
