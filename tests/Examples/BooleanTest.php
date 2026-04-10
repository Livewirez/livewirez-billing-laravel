<?php


declare(strict_types=1);

namespace Livewirez\Tests\Examples;

use PHPUnit\Framework\TestCase;

final class BooleanTest extends TestCase
{
    public function testisTrue(): void
    {
        $this->assertIsBool(1000 === (10 * 10 * 10));
    }
}
