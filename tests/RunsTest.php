<?php

declare(strict_types=1);

namespace Porta\SymfonyLsp\Tests;

use PHPUnit\Framework\TestCase;
use Porta\SymfonyLsp\Runner;
use Porta\SymfonyLsp\Runs;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;

/** What an install has running is kept while it runs, and stopped on demand — never to call back. */
final class RunsTest extends TestCase
{
    use PumpsLoop;

    public function testWhatRunsIsKeptUntilItEnds(): void
    {
        $runs = new Runs();
        $run = Runner::default(runs: $runs);
        $out = null;

        $run([\PHP_BINARY, '-r', 'echo "done";'], \sys_get_temp_dir(), static function (int $code, string $stdout) use (&$out): void {
            $out = $stdout;
        });
        $this->assertCount(1, Probe::get($runs, 'running'), 'kept while it runs');

        $this->await(static function () use (&$out): bool {
            return null !== $out;
        }, what: 'the program to end');
        $this->assertSame('done', $out);
        $this->assertSame([], Probe::get($runs, 'running'), 'and forgotten once it ended');
    }

    public function testStoppedItNeverCallsBack(): void
    {
        $runs = new Runs();
        $run = Runner::default(runs: $runs);
        $called = false;
        $run([\PHP_BINARY, '-r', 'sleep(5);'], \sys_get_temp_dir(), static function () use (&$called): void {
            $called = true;
        });

        $runs->cancel();
        $this->pump(0.3);

        $this->assertFalse($called, 'a plugin disabled mid-install hears nothing back');
        $this->assertSame([], Probe::get($runs, 'running'));
    }
}
