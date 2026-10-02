<?php

declare(strict_types=1);

namespace Porta\SymfonyLsp;

use Porta\Support\AsyncProcess;

/**
 * The programs an install has running — curl, tar, the new binary asked its
 * version — so a plugin disabled mid-install stops them, rather than have
 * a download finish into a plugin that is gone.
 */
final class Runs
{
    /** @var array<int, AsyncProcess> by object id */
    private array $running = [];

    public function keep(AsyncProcess $process): void
    {
        $this->running[\spl_object_id($process)] = $process;
    }

    public function forget(AsyncProcess $process): void
    {
        unset($this->running[\spl_object_id($process)]);
    }

    /** Stop them all: a cancelled process never calls back. */
    public function cancel(): void
    {
        foreach ($this->running as $process) {
            $process->cancel();
        }
        $this->running = [];
    }
}
