<?php

declare(strict_types=1);

namespace Porta\SymfonyLsp\Tests;

use PHPUnit\Framework\TestCase;
use Porta\SymfonyLsp\InstalledVersion;
use Porta\SymfonyLsp\Release;

/**
 * The plugin's notion of WHAT is installed, which it had none of: "Update
 * Server" was the install closure under another name and re-downloaded ~9MB
 * unconditionally.
 *
 * Every test drives a scripted runner, so nothing here spawns a process.
 */
final class InstalledVersionTest extends TestCase
{
    /** @var list<array{0: list<string>, 1: string}> argv and cwd of every spawn */
    private array $ran = [];

    /**
     * A runner that answers one `--version` call. $answers is consumed in
     * order, so a test can prove a SECOND probe happened (or did not).
     *
     * @param list<array{0: int, 1: string}> $answers exit code and stdout, per call
     *
     * @return \Closure(list<string>, string, \Closure(int, string): void): void
     */
    private function runner(array $answers): \Closure
    {
        return function (array $argv, string $cwd, \Closure $onExit) use (&$answers): void {
            $this->ran[] = [$argv, $cwd];
            [$code, $out] = \array_shift($answers) ?? [-1, ''];
            $onExit($code, $out);
        };
    }

    public function testProbeAsksTheBinaryAndCachesTheAnswer(): void
    {
        $versions = new InstalledVersion(new Release(), $this->runner([[0, "Symfony Language Tools 0.21.0\n"]]));
        $this->assertSame('', $versions->known(), 'nothing is known before anything is asked');

        $versions->probe('/opt/porta/bin/symfony-lsp');

        $this->assertSame('0.21.0', $versions->known());
        $this->assertSame([['/opt/porta/bin/symfony-lsp', '--version'], '/opt/porta/bin'], $this->ran[0]);
    }

    /**
     * known() is read once per render by the status cell and the settings row.
     * A probe per render would spawn a process per frame.
     */
    public function testASecondProbeOfTheSamePathDoesNotSpawnAgain(): void
    {
        $versions = new InstalledVersion(new Release(), $this->runner([[0, 'Symfony Language Tools 0.21.0']]));
        $versions->probe('/bin/symfony-lsp');
        $versions->probe('/bin/symfony-lsp');

        $this->assertCount(1, $this->ran);
        $this->assertSame('0.21.0', $versions->known());
    }

    /** A copy installed somewhere else is a different question, so it is asked. */
    public function testADifferentPathIsProbedAgain(): void
    {
        $versions = new InstalledVersion(new Release(), $this->runner([
            [0, 'Symfony Language Tools 0.21.0'],
            [0, 'Symfony Language Tools 0.22.0'],
        ]));
        $versions->probe('/bin/symfony-lsp');
        $versions->probe('/usr/local/bin/symfony-lsp');

        $this->assertCount(2, $this->ran);
        $this->assertSame('0.22.0', $versions->known());
    }

    /**
     * No binary is not "the last version I saw": the next question is "can I
     * update this?", and answering it from a version the disk no longer has is
     * worse than answering "I do not know".
     */
    public function testProbingNothingForgets(): void
    {
        $versions = new InstalledVersion(new Release(), $this->runner([[0, 'Symfony Language Tools 0.21.0']]));
        $versions->probe('/bin/symfony-lsp');
        $this->assertSame('0.21.0', $versions->known());

        $noBinary = null;
        $versions->probe($noBinary);

        $this->assertSame('', $versions->known());
        $this->assertCount(1, $this->ran, 'there was nothing to ask');
    }

    public function testABinaryThatWillNotRunLeavesTheVersionUnknown(): void
    {
        $versions = new InstalledVersion(new Release(), $this->runner([[126, '']]));
        $versions->probe('/bin/symfony-lsp');

        $this->assertSame('', $versions->known());
    }

    /** A build that runs but names itself in some other shape still runs. */
    public function testAnUnparseableVersionLineIsUnknownNotFatal(): void
    {
        $versions = new InstalledVersion(new Release(), $this->runner([[0, 'some fork, no version']]));
        $versions->probe('/bin/symfony-lsp');

        $this->assertSame('', $versions->known());
    }

    /**
     * A bare name is PATH-resolved by the spawn; dirname() on it would hand
     * curl the CWD as a directory, and `.` is the project root — the one
     * directory that can be deleted underneath a long-lived IDE.
     */
    public function testABareNameRunsFromTheSystemTempDirectory(): void
    {
        $versions = new InstalledVersion(new Release(), $this->runner([[0, 'Symfony Language Tools 0.21.0']]));
        $versions->probe('symfony-lsp');

        $this->assertSame([['symfony-lsp', '--version'], \sys_get_temp_dir()], $this->ran[0]);
    }

    /**
     * The install already asked the binary as its smoke test, so the answer is
     * in hand — the settings row must not go blank for one round trip.
     */
    public function testRememberFillsTheCacheWithoutSpawning(): void
    {
        $versions = new InstalledVersion(new Release(), $this->runner([]));
        $versions->remember('/bin/symfony-lsp', '0.22.0');

        $this->assertSame('0.22.0', $versions->known());

        $versions->probe('/bin/symfony-lsp');
        $this->assertSame([], $this->ran, 'the remembered path needs no probe');
    }

    public function testTheCallbackRunsOnEveryPath(): void
    {
        $versions = new InstalledVersion(new Release(), $this->runner([[0, 'Symfony Language Tools 0.21.0']]));
        $calls = 0;
        $count = static function () use (&$calls): void {
            ++$calls;
        };

        $noBinary = null;
        $versions->probe($noBinary, $count); // forgot
        $versions->probe('/bin/symfony-lsp', $count); // asked
        $versions->probe('/bin/symfony-lsp', $count); // cached

        $this->assertSame(3, $calls, 'the caller is told either way, or an update never continues');
    }

    /**
     * Two probes in flight would ask the same question of the same file, and
     * the second answer would land after the first — so the second is dropped
     * rather than queued. The runner here never answers, which is what an
     * in-flight probe looks like.
     */
    public function testAProbeInFlightDoesNotStartASecond(): void
    {
        $never = function (array $argv, string $cwd, \Closure $onExit): void {
            $this->ran[] = [$argv, $cwd];
        };
        $versions = new InstalledVersion(new Release(), $never);
        $versions->probe('/bin/symfony-lsp');
        $versions->probe('/bin/symfony-lsp');

        $this->assertCount(1, $this->ran);
    }
}
