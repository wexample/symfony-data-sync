<?php

namespace Wexample\SymfonyDataSync\Tests\Integration;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Wexample\SymfonyDataSync\Class\SyncDefinition;
use Wexample\SymfonyDataSync\Service\DoctrineLinkStore;
use Wexample\SymfonyDataSync\Testing\InMemoryLocalStore;
use Wexample\SymfonyDataSync\Testing\InMemoryRemoteAdapter;

class DoctrineLinkStoreTest extends KernelTestCase
{
    private DoctrineLinkStore $store;

    private SyncDefinition $definition;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $this->store = self::getContainer()->get('test.link_store');
        $this->definition = new SyncDefinition('users', stdClass::class, new InMemoryRemoteAdapter(), new InMemoryLocalStore(), $this->store);
    }

    public function testALinkIsStoredAndSynced(): void
    {
        $this->store->link($this->definition, 'u1', 'r1');
        $this->assertNull($this->store->findByLocal($this->definition, 'u1')->lastSyncedHash);

        $this->store->touch($this->definition, 'u1', 'r1', 'hash');

        $record = $this->store->findByRemote($this->definition, 'r1');
        $this->assertSame('u1', $record->localId);
        $this->assertSame('hash', $record->lastSyncedHash);
        $this->assertNotNull($record->lastSyncedAt);
    }

    public function testAnUnlinkedPairIsForgotten(): void
    {
        $this->store->link($this->definition, 'u1', 'r1');
        $this->store->unlink($this->definition, 'u1', 'r1');

        $this->assertSame([], $this->store->all($this->definition));
    }

    public function testARemoteItemCannotBeLinkedTwice(): void
    {
        $this->store->link($this->definition, 'u1', 'r1');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->store->link($this->definition, 'u2', 'r1');
    }

    public function testALocalEntityCannotBeLinkedTwice(): void
    {
        $this->store->link($this->definition, 'u1', 'r1');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->store->link($this->definition, 'u1', 'r2');
    }
}
