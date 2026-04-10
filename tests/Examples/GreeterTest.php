<?php 

declare(strict_types=1);

namespace Livewirez\Tests\Examples;

use PHPUnit\Framework\TestCase;

final class GreeterTest extends TestCase
{
    public function testGreetsWithName(): void
    {
        $name = 'Snow Princess';

        $greeting = 'Hello, ' . $name . '!';

        $this->assertSame('Hello, Snow Princess!', $greeting);
    }
}