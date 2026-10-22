<?php

namespace Wexample\SymfonyDataSync\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Wexample\SymfonyDataSync\DependencyInjection\Configuration;
use Wexample\SymfonyDataSync\Enum\SyncOutcome;
use Wexample\SymfonyDataSync\Enum\SyncSide;
use Wexample\SymfonyDataSync\Service\DoctrineLinkStore;
use Wexample\SymfonyDataSync\Service\Matcher;
use Wexample\SymfonyDataSync\Service\SyncDefinitionRegistry;
use Wexample\SymfonyDataSync\Service\SyncExecutor;
use Wexample\SymfonyDataSync\Service\SyncPlanner;
use Wexample\SymfonyDataSync\Service\SyncResolver;
use Wexample\SymfonyDataSync\Testing\InMemoryLocalStore;
use Wexample\SymfonyDataSync\Testing\InMemoryRemoteAdapter;

class SyncResolverTest extends TestCase
{
    private InMemoryLocalStore $locals;

    private InMemoryRemoteAdapter $remote;

    private SyncResolver $resolver;

    private SyncPlanner $planner;

    private SyncDefinitionRegistry $registry;

    protected function setUp(): void
    {
        // A link kept in a column holds no history: a "both" field that differs is a conflict.
        $this->locals = new InMemoryLocalStore(['u1' => ['email' => 'local@example.test', 'name' => 'Ada', 'remoteId' => 'r1']]);
        $this->remote = new InMemoryRemoteAdapter(['r1' => ['email' => 'remote@example.test', 'name' => 'Ada L.']]);

        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'definitions' => ['users' => [
                'local' => 'stdClass',
                'adapter' => 'remote',
                'local_store' => 'locals',
                'link_property' => 'remoteId',
                'fields' => ['email' => ['remote' => 'email', 'direction' => 'both'], 'name' => 'name'],
            ]],
        ]]);
        $this->registry = new SyncDefinitionRegistry(
            $this->createStub(DoctrineLinkStore::class),
            $config['definitions'],
            new ServiceLocator(['remote' => fn () => $this->remote, 'locals' => fn () => $this->locals]),
        );
        $this->planner = new SyncPlanner(new Matcher());
        $this->resolver = new SyncResolver($this->registry, $this->planner, new SyncExecutor());
    }

    public function testKeepingTheLocalValueWritesItRemotely(): void
    {
        $report = $this->resolver->resolve('users', 'u1', SyncSide::Local);

        $this->assertSame(SyncOutcome::Success, $report->outcomes[0]->outcome);
        $this->assertSame('local@example.test', $this->remote->items['r1']['email']);
        $this->assertSame('Ada', $this->remote->items['r1']['name']);
        $this->assertSame([], $this->planner->plan($this->registry->get('users'))->withoutUpToDate()->relations);
    }

    public function testKeepingTheRemoteValueWritesItLocally(): void
    {
        $this->resolver->resolve('users', 'u1', SyncSide::Remote);

        $this->assertSame('remote@example.test', $this->locals->entities['u1']->email);
        // The local-owned field still goes out, the pair being settled at once.
        $this->assertSame('Ada', $this->remote->items['r1']['name']);
    }

    public function testThereMustBeAConflict(): void
    {
        $this->resolver->resolve('users', 'u1', SyncSide::Local);

        $this->expectExceptionMessage('no field conflict');
        $this->resolver->resolve('users', 'u1', SyncSide::Local);
    }
}
