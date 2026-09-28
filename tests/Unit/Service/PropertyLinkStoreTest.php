<?php

namespace Wexample\SymfonyDataSync\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use stdClass;
use Wexample\SymfonyDataSync\Class\SyncDefinition;
use Wexample\SymfonyDataSync\Service\PropertyLinkStore;
use Wexample\SymfonyDataSync\Testing\InMemoryLinkStore;
use Wexample\SymfonyDataSync\Testing\InMemoryLocalStore;
use Wexample\SymfonyDataSync\Testing\InMemoryRemoteAdapter;

class PropertyLinkStoreTest extends TestCase
{
    public function testTheRemoteIdLivesInTheEntity(): void
    {
        $locals = new InMemoryLocalStore([
            'u1' => ['username' => 'ada', 'chatId' => 'r1'],
            'u2' => ['username' => 'bob', 'chatId' => null],
        ]);
        $store = new PropertyLinkStore($locals, 'chatId');
        $definition = new SyncDefinition('users', stdClass::class, new InMemoryRemoteAdapter(), new InMemoryLinkStore());

        $this->assertSame('r1', $store->findByLocal($definition, 'u1')->remoteId);
        $this->assertCount(1, $store->all($definition));

        $store->link($definition, 'u2', 'r2');
        $store->unlink($definition, 'u1', 'r1');

        $this->assertSame('r2', $locals->entities['u2']->chatId);
        $this->assertNull($locals->entities['u1']->chatId);
        $this->assertSame('u2', $store->findByRemote($definition, 'r2')->localId);
    }
}
