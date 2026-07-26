<?php

namespace tests\unit\Espo\Modules\ElevateResourceManagement\Domain;

use Espo\Modules\ElevateResourceManagement\Domain\Lifecycle;
use PHPUnit\Framework\TestCase;

final class LifecycleTest extends TestCase
{
    public function testOpenTargetStaysOpen(): void
    {
        self::assertSame(Lifecycle::OPEN, Lifecycle::forCompletion(false, false, 2, 2));
    }

    public function testIncompleteTimeRequiresAttention(): void
    {
        self::assertSame(Lifecycle::ADD_TIME, Lifecycle::forCompletion(true, false, 2, 1));
    }

    public function testCompleteTimeIsReady(): void
    {
        self::assertSame(Lifecycle::READY, Lifecycle::forCompletion(true, false, 2, 2));
    }
}
