<?php

namespace Wexample\SymfonyDataSync\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Wexample\SymfonyDataSync\Class\FieldMapping;
use Wexample\SymfonyDataSync\Class\LinkRecord;
use Wexample\SymfonyDataSync\Class\MatchRule\ExactFieldRule;
use Wexample\SymfonyDataSync\Class\SyncDefinition;
use Wexample\SymfonyDataSync\Class\SyncReport;
use Wexample\SymfonyDataSync\Enum\FieldDirection;
use Wexample\SymfonyDataSync\Enum\MatchNormalizer;
use Wexample\SymfonyDataSync\Enum\OrphanLocalPolicy;
use Wexample\SymfonyDataSync\Enum\OrphanRemotePolicy;
use Wexample\SymfonyDataSync\Enum\SyncOperation;
use Wexample\SymfonyDataSync\Enum\SyncOutcome;
use Wexample\SymfonyDataSync\Event\PostOperationEvent;
use Wexample\SymfonyDataSync\Service\Matcher;
use Wexample\SymfonyDataSync\Service\SyncExecutor;
use Wexample\SymfonyDataSync\Service\SyncPlanner;
use Wexample\SymfonyDataSync\Testing\InMemoryLinkStore;
use Wexample\SymfonyDataSync\Testing\InMemoryLocalStore;
use Wexample\SymfonyDataSync\Testing\InMemoryRemoteAdapter;

/**
 * The scenarios of network's lost MockTestUserRemoteSyncManager, run end to end.
 */
class SyncExecutorTest extends TestCase
{
    private InMemoryLocalStore $locals;

    private InMemoryRemoteAdapter $remote;

    private InMemoryLinkStore $links;

    protected function setUp(): void
    {
        $this->locals = new InMemoryLocalStore([
            'u1' => ['username' => 'ada', 'email' => 'ada@example.test'],
            'u2' => ['username' => 'bob', 'email' => 'bob@example.test'],
        ]);
        $this->remote = new InMemoryRemoteAdapter();
        $this->links = new InMemoryLinkStore();
    }

    public function testRemoteMissing(): void
    {
        $this->sync(orphanLocal: OrphanLocalPolicy::CreateRemote);

        $this->assertCount(2, $this->remote->items);
        $this->assertCount(2, $this->links->links['users']);
        $this->assertSame(['username' => 'ada', 'email' => 'ada@example.test'], $this->remote->items['remote-1']);
    }

    public function testRemoteNotSynced(): void
    {
        $this->remote->items = ['r9' => ['username' => 'cid', 'email' => 'cid@example.test']];

        $this->sync(orphanLocal: OrphanLocalPolicy::CreateRemote, orphanRemote: OrphanRemotePolicy::CreateLocal);

        $this->assertCount(3, $this->locals->entities);
        $this->assertCount(3, $this->remote->items);
        $this->assertCount(3, $this->links->links['users']);
    }

    public function testShouldUpdate(): void
    {
        $this->remote->items = ['r1' => ['username' => 'old_name', 'email' => 'ada@example.test']];
        $this->links->seed('users', new LinkRecord('u1', 'r1'));

        $report = $this->sync();

        $this->assertSame('ada', $this->remote->items['r1']['username']);
        $this->assertSame(SyncOperation::RemoteUpdate, $report->outcomes[0]->relation->operation);
        $this->assertNotNull($this->links->findByLocal($this->definition(), 'u1')->lastSyncedHash);
    }

    public function testShouldRemoveRemoteOnlyWhenThePolicySaysSo(): void
    {
        $this->remote->items = ['r9' => ['username' => 'cid', 'email' => 'cid@example.test']];

        $this->sync();
        $this->assertArrayHasKey('r9', $this->remote->items);

        $this->sync(orphanRemote: OrphanRemotePolicy::RemoveRemote);
        $this->assertArrayNotHasKey('r9', $this->remote->items);
    }

    public function testACreationWritesPulledFieldsToo(): void
    {
        // A pulled field left out of the creation would come back empty and
        // erase the local value on the next run.
        $this->locals = new InMemoryLocalStore(['u1' => ['username' => 'ada', 'email' => 'ada@example.test', 'name' => 'Ada']]);
        $definition = new SyncDefinition(
            'users', stdClass::class, $this->remote, $this->locals, $this->links,
            fields: [new FieldMapping('username', 'username'), new FieldMapping('name', 'name', FieldDirection::RemoteToLocal)],
            orphanLocal: OrphanLocalPolicy::CreateRemote,
        );
        $planner = new SyncPlanner(new Matcher());

        (new SyncExecutor())->execute($planner->plan($definition), ['users' => $definition]);

        $this->assertSame('Ada', $this->remote->items['remote-1']['name']);
        $this->assertSame([], $planner->plan($definition)->withoutUpToDate()->relations);
    }

    public function testADryRunWritesNothing(): void
    {
        $report = $this->sync(orphanLocal: OrphanLocalPolicy::CreateRemote, dryRun: true);

        $this->assertSame([], $this->remote->calls);
        $this->assertSame([], $this->links->links);
        $this->assertSame(['nothing_to_do' => 2], $report->countByOutcome());
        $this->assertSame(['remote_create' => 2], $report->countByOperation());
    }

    public function testAStaleLinkConvergesOverTwoRuns(): void
    {
        $this->remote->items = ['r1' => ['username' => 'ada', 'email' => 'ada@example.test']];
        $this->links->seed('users', new LinkRecord('u1', 'gone'));

        $first = $this->sync();
        $second = $this->sync();

        $this->assertSame(SyncOperation::LocalUnlink, $first->outcomes[0]->relation->operation);
        $this->assertSame(SyncOperation::LocalLink, $second->outcomes[0]->relation->operation);
        $this->assertSame('r1', $this->links->findByLocal($this->definition(), 'u1')->remoteId);
    }

    public function testAFailureIsRecordedAndTheRunGoesOn(): void
    {
        $remote = new class() extends InMemoryRemoteAdapter {
            public function create(array $fields): \Wexample\SymfonyDataSync\Class\RemoteItem
            {
                if ('ada' === $fields['username']) {
                    throw new \RuntimeException('Username is already in use');
                }

                return parent::create($fields);
            }
        };
        $this->remote = $remote;

        $report = $this->sync(orphanLocal: OrphanLocalPolicy::CreateRemote);

        $this->assertSame(SyncOutcome::Error, $report->outcomes[0]->outcome);
        $this->assertSame('Username is already in use', $report->outcomes[0]->message);
        $this->assertSame(SyncOutcome::Success, $report->outcomes[1]->outcome);
        $this->assertTrue($report->hasErrors());
    }

    public function testAVanishedItemIsSkipped(): void
    {
        $this->remote->items = ['r1' => ['username' => 'old', 'email' => 'ada@example.test']];
        $this->links->seed('users', new LinkRecord('u1', 'r1'));
        $definition = $this->definition();
        $plan = (new SyncPlanner(new Matcher()))->plan($definition);
        unset($this->remote->items['r1']);

        $report = (new SyncExecutor())->execute($plan, ['users' => $definition]);

        $this->assertSame(SyncOutcome::Skipped, $report->outcomes[0]->outcome);
    }

    public function testEveryOperationIsAnnounced(): void
    {
        $dispatcher = new EventDispatcher();
        $seen = [];
        $dispatcher->addListener(PostOperationEvent::class, static function (PostOperationEvent $event) use (&$seen): void {
            $seen[] = $event->outcome->relation->local->id;
        });
        $definition = $this->definition(OrphanLocalPolicy::CreateRemote);

        (new SyncExecutor($dispatcher))->execute((new SyncPlanner(new Matcher()))->plan($definition), ['users' => $definition]);

        $this->assertSame(['u1', 'u2'], $seen);
    }

    public function testTheReportIsAStableArray(): void
    {
        $report = $this->sync(orphanLocal: OrphanLocalPolicy::CreateRemote, dryRun: true);

        $this->assertSame([
            'definition' => 'users',
            'operation' => 'remote_create',
            'side' => 'remote',
            'reason' => 'No remote item matches.',
            'outcome' => 'nothing_to_do',
            'message' => null,
            'local' => ['id' => 'u1', 'fields' => ['username' => 'ada', 'email' => 'ada@example.test']],
            'remote' => null,
            'link' => null,
            'diffs' => [],
            'context' => [],
        ], $report->toArray()['relations'][0]);
    }

    private function sync(
        OrphanLocalPolicy $orphanLocal = OrphanLocalPolicy::Report,
        OrphanRemotePolicy $orphanRemote = OrphanRemotePolicy::Report,
        bool $dryRun = false,
    ): SyncReport {
        $definition = $this->definition($orphanLocal, $orphanRemote);

        return (new SyncExecutor())->execute((new SyncPlanner(new Matcher()))->plan($definition), ['users' => $definition], $dryRun);
    }

    private function definition(
        OrphanLocalPolicy $orphanLocal = OrphanLocalPolicy::Report,
        OrphanRemotePolicy $orphanRemote = OrphanRemotePolicy::Report,
    ): SyncDefinition {
        return new SyncDefinition(
            'users',
            stdClass::class,
            $this->remote,
            $this->locals,
            $this->links,
            matchRules: [new ExactFieldRule('email', 'email', [MatchNormalizer::Email])],
            fields: [new FieldMapping('username', 'username'), new FieldMapping('email', 'email')],
            orphanRemote: $orphanRemote,
            orphanLocal: $orphanLocal,
        );
    }
}
