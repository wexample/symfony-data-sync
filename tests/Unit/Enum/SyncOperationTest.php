<?php

namespace Wexample\SymfonyDataSync\Tests\Unit\Enum;

use PHPUnit\Framework\TestCase;
use Wexample\SymfonyDataSync\Enum\SyncOperation;
use Wexample\SymfonyDataSync\Enum\SyncSide;

class SyncOperationTest extends TestCase
{
    public function testEveryOperationSaysWhereItWrites(): void
    {
        $this->assertSame(SyncSide::Local, SyncOperation::LocalUnlink->side());
        $this->assertSame(SyncSide::Remote, SyncOperation::RemoteDisable->side());
        $this->assertSame(SyncSide::None, SyncOperation::Candidate->side());
        $this->assertFalse(SyncOperation::Unmatched->isAction());
        $this->assertTrue(SyncOperation::LocalCreate->isAction());
    }
}
