<?php

namespace Wexample\SymfonyDataSync\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Wexample\SymfonyDataSync\Class\MatchRule\ExactFieldRule;
use Wexample\SymfonyDataSync\Class\MatchRule\FuzzyFieldRule;
use Wexample\SymfonyDataSync\Enum\ConflictPolicy;
use Wexample\SymfonyDataSync\Enum\ExcludedLocalPolicy;
use Wexample\SymfonyDataSync\Enum\FieldDirection;
use Wexample\SymfonyDataSync\Enum\OrphanLocalPolicy;
use Wexample\SymfonyDataSync\Enum\OrphanRemotePolicy;
use Wexample\SymfonyDataSync\Service\DoctrineLinkStore;
use Wexample\SymfonyDataSync\Service\PropertyLinkStore;
use Wexample\SymfonyDataSync\Service\SyncDefinitionRegistry;
use Wexample\SymfonyDataSync\Testing\InMemoryLocalStore;
use Wexample\SymfonyDataSync\Testing\InMemoryRemoteAdapter;

class SyncDefinitionRegistryTest extends KernelTestCase
{
    public function testADefinitionIsBuiltFromConfiguration(): void
    {
        $definition = $this->registry()->get('users');

        $this->assertInstanceOf(InMemoryRemoteAdapter::class, $definition->adapter);
        $this->assertInstanceOf(InMemoryLocalStore::class, $definition->localStore);
        $this->assertInstanceOf(DoctrineLinkStore::class, $definition->linkStore);
        $this->assertInstanceOf(ExactFieldRule::class, $definition->matchRules[0]);
        $this->assertInstanceOf(FuzzyFieldRule::class, $definition->matchRules[1]);
        $this->assertSame(2.0, $definition->matchRules[1]->fields[0]['weight']);
        $this->assertSame('username', $definition->fields[0]->remoteField);
        $this->assertSame(FieldDirection::LocalToRemote, $definition->fields[0]->direction);
        $this->assertSame('mail', $definition->fields[1]->remoteField);
        $this->assertSame(FieldDirection::Both, $definition->fields[1]->direction);
        $this->assertTrue($definition->isRemoteExcluded(new \Wexample\SymfonyDataSync\Class\RemoteItem('bot', ['roles' => ['bot']])));
        $this->assertSame(OrphanLocalPolicy::CreateRemote, $definition->orphanLocal);
        $this->assertSame(ConflictPolicy::LocalWins, $definition->conflict);
    }

    public function testDefaultsNeverDestroyNorCreate(): void
    {
        $definition = $this->registry()->get('minimal');

        $this->assertSame(OrphanRemotePolicy::Report, $definition->orphanRemote);
        $this->assertSame(OrphanLocalPolicy::Report, $definition->orphanLocal);
        $this->assertSame(ExcludedLocalPolicy::Ignore, $definition->excludedLocal);
        $this->assertSame(ConflictPolicy::Report, $definition->conflict);
        $this->assertSame(0.95, $definition->thresholds->autoLink);
        $this->assertInstanceOf(PropertyLinkStore::class, $definition->linkStore);
    }

    public function testAnUnknownKeyNamesTheDeclaredOnes(): void
    {
        $this->expectExceptionMessage('Declared: users, minimal.');

        $this->registry()->get('missing');
    }

    private function registry(): SyncDefinitionRegistry
    {
        return self::getContainer()->get('test.registry');
    }
}
