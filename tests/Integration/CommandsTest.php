<?php

namespace Wexample\SymfonyDataSync\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Wexample\SymfonyDataSync\Testing\InMemoryLocalStore;
use Wexample\SymfonyDataSync\Testing\InMemoryRemoteAdapter;

class CommandsTest extends KernelTestCase
{
    private InMemoryRemoteAdapter $remote;

    private InMemoryLocalStore $locals;

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $this->remote = self::getContainer()->get('test.remote');
        $this->locals = self::getContainer()->get('test.locals');
        $this->remote->items = [
            'r1' => ['username' => 'ada', 'mail' => 'ADA@example.test'],
            'r2' => ['username' => 'rocket.cat', 'mail' => 'cat@example.test', 'roles' => ['bot']],
        ];
        $this->locals->entities = [
            'u1' => (object) ['username' => 'ada', 'email' => 'ada@example.test', 'enabled' => true, 'name' => 'Ada', 'city' => 'Lyon'],
            'u2' => (object) ['username' => 'bob', 'email' => 'bob@example.test', 'enabled' => true, 'name' => 'Bob', 'city' => 'Paris'],
        ];
    }

    public function testADryRunPrintsAStablePlanAndWritesNothing(): void
    {
        $tester = $this->command('data-sync:run', ['definition' => 'users', '--dry-run' => true, '--format' => 'json']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['local_link' => 1, 'remote_create' => 1], $report['counts']);
        $this->assertSame(['local_link', 'remote_create'], array_column($report['relations'], 'operation'));
        $this->assertSame(['u1', 'u2'], array_column(array_column($report['relations'], 'local'), 'id'));
        $this->assertSame([], $this->remote->calls);
    }

    public function testARunConvergesThenGoesQuiet(): void
    {
        // Run 1 links u1 and creates u2 remotely; run 2 aligns u1's mail, whose
        // case differed ("both" direction, local_wins); run 3 has nothing left.
        $this->command('data-sync:run', ['definition' => 'users']);
        $this->command('data-sync:run', ['definition' => 'users']);
        $third = $this->command('data-sync:run', ['definition' => 'users', '--format' => 'json']);

        $this->assertSame([['create', 'remote-1'], ['update', 'r1']], $this->remote->calls);
        $this->assertSame('ada@example.test', $this->remote->items['r1']['mail']);
        $this->assertSame([], json_decode($third->getDisplay(), true)['relations']);
    }

    public function testOnlyKeepsExactOperations(): void
    {
        $tester = $this->command('data-sync:run', ['definition' => 'users', '--dry-run' => true, '--only' => 'remote_create', '--format' => 'json']);

        $this->assertSame(['remote_create'], array_column(json_decode($tester->getDisplay(), true)['relations'], 'operation'));
    }

    public function testAnUnknownOperationIsRefused(): void
    {
        $this->expectExceptionMessage('Unknown operation "remote"');

        $this->command('data-sync:run', ['definition' => 'users', '--dry-run' => true, '--only' => 'remote']);
    }

    public function testOneEntityIsPlannedWithoutListing(): void
    {
        $this->remote->refuseListing = true;

        $tester = $this->command('data-sync:run', ['definition' => 'users', '--dry-run' => true, '--local-id' => 'u1', '--format' => 'json']);

        $this->assertSame(['local_link'], array_column(json_decode($tester->getDisplay(), true)['relations'], 'operation'));
    }

    public function testAPairIsLinkedByHandOnce(): void
    {
        $tester = $this->command('data-sync:link', ['definition' => 'users', 'localId' => 'u2', 'remoteId' => 'r1']);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());

        $this->expectExceptionMessage('already linked');
        $this->command('data-sync:link', ['definition' => 'users', 'localId' => 'u1', 'remoteId' => 'r1']);
    }

    public function testTheDefinitionsAreListed(): void
    {
        $display = $this->command('data-sync:definitions', [])->getDisplay();

        $this->assertStringContainsString('users', $display);
        $this->assertStringContainsString('email = mail', $display);
        $this->assertStringContainsString('report / create_remote', $display);
    }

    private function command(string $name, array $input): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find($name));
        $tester->execute($input);

        return $tester;
    }
}
