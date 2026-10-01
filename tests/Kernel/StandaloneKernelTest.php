<?php

declare(strict_types=1);

namespace App\Withdrawing\Tests\Kernel;

use App\Withdrawing\Kernel;
use PHPUnit\Framework\TestCase;

final class StandaloneKernelTest extends TestCase
{
    public function testStandaloneKernelBoots(): void
    {
        $kernel = new Kernel('test', true);

        try {
            $kernel->boot();

            self::assertTrue($kernel->getContainer()->has('kernel'));
        } finally {
            $kernel->shutdown();
        }
    }
}
