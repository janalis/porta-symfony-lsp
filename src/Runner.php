<?php

declare(strict_types=1);

namespace Porta\SymfonyLsp;

use Porta\Support\AsyncProcess;

/**
 * The one way this plugin spawns anything: argv, cwd, and a callback that
 * fires exactly once with the exit code and whatever the child wrote to
 * stdout.
 *
 * It exists so {@see Installer} and {@see InstalledVersion} cannot end up with
 * two subtly different notions of "run a program" — in particular of what a
 * REFUSED spawn means. `AsyncProcess::start()` returns false when nothing was
 * spawned at all (PHP resolves the program itself for the array form), and no
 * callback will ever fire; a caller that ignores it waits forever, holding its
 * single-flight flag. Mapping that to `onExit(-1, '')` is the whole reason
 * this is shared rather than written twice.
 */
final class Runner
{
    /**
     * How long a `--version` question may take before the answer is never
     * coming.
     *
     * The install's own ceiling is 300s, which is right for a ~9MB download
     * and absurd for a program printing one line. It matters because the thing
     * being asked is whatever the `binary` setting points at: a wrapper script
     * that ignores `--version` and waits on stdin would otherwise hold a
     * process for five minutes per window.
     */
    public const float PROBE_TIMEOUT = 5.0;

    /**
     * The optional fourth argument receives stdout as it arrives, which is how
     * a ~9MB download reports a percentage instead of going quiet for half a
     * minute.
     *
     * @param float|null $timeout seconds before the child is cancelled and reported as exit -1;
     *        null takes {@see \Porta\Support\AsyncProcess::EXTERNAL_TOOL_TIMEOUT}, the
     *        network-bound default
     *
     * @return \Closure(list<string>, string, \Closure(int, string): void, (\Closure(string): void)|null): void
     */
    public static function default(?float $timeout = null): \Closure
    {
        $timeout ??= AsyncProcess::EXTERNAL_TOOL_TIMEOUT;

        return /** @param list<string> $argv */ static function (array $argv, string $cwd, \Closure $onExit, ?\Closure $onChunk = null) use ($timeout): void {
            if (!new AsyncProcess($argv, $cwd, timeout: $timeout)->start($onExit, $onChunk)) {
                $noOutput = '';
                $onExit(-1, $noOutput);
            }
        };
    }
}
