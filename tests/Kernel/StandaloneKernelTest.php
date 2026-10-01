<?php

declare(strict_types=1);

namespace App\Withdrawing\Tests\Kernel;

use App\Withdrawing\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

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

    public function testStandaloneConsoleCanListCommands(): void
    {
        $kernel = new Kernel('test', true);
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();

        try {
            $exitCode = $application->run(new ArrayInput(['command' => 'list']), $output);

            self::assertSame(0, $exitCode, $output->fetch());
        } finally {
            $kernel->shutdown();
        }
    }
}
