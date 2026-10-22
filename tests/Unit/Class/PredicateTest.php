<?php

namespace Wexample\SymfonyDataSync\Tests\Unit\Class;

use PHPUnit\Framework\TestCase;
use Wexample\SymfonyDataSync\Class\Predicate;
use Wexample\SymfonyDataSync\Enum\PredicateOperator;

class PredicateTest extends TestCase
{
    public function testTheLegacyProtectionsAreExpressible(): void
    {
        $bot = new Predicate('roles', PredicateOperator::Contains, 'bot');
        $protected = new Predicate('username', PredicateOperator::In, ['admin', 'wexbot']);
        $noEmail = new Predicate('email', PredicateOperator::Empty);

        $this->assertTrue($bot->matches(['roles' => ['user', 'bot']]));
        $this->assertFalse($bot->matches(['roles' => ['user']]));
        $this->assertTrue($protected->matches(['username' => 'wexbot']));
        $this->assertTrue($noEmail->matches(['email' => '']));
        $this->assertTrue($noEmail->matches([]));
        $this->assertFalse($noEmail->matches(['email' => 'ada@example.test']));
    }
}
