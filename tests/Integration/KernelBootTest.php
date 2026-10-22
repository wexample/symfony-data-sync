<?php

namespace Wexample\SymfonyDataSync\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Wexample\SymfonyDataSync\WexampleSymfonyDataSyncBundle;

class KernelBootTest extends KernelTestCase
{
    public function testTheBundleBootsInTheFixtureKernel(): void
    {
        $this->assertInstanceOf(
            WexampleSymfonyDataSyncBundle::class,
            self::bootKernel()->getBundle('WexampleSymfonyDataSyncBundle')
        );
    }
}
