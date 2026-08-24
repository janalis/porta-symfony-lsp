<?php

declare(strict_types=1);

namespace Porta\SymfonyLsp\Tests;

use PHPUnit\Framework\TestCase;
use Porta\SymfonyLsp\Installer;
use Porta\SymfonyLsp\Release;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;

/**
 * The download/verify/extract state machine with a scripted runner — no
 * network, no curl. Each step's argv is matched on what the Installer must
 * ask for; the fake produces (or refuses) the on-disk effect a real curl/tar
 * would have had.
 */
final class InstallerTest extends TestCase
{
    use PreservesPath;
    use PumpsLoop;

    private const string TAG_JSON = '{"tag_name":"v9.9.9"}';
    private const string TARBALL_BYTES = 'not really gzip, and it does not matter';

    /** The step log every scripted runner appends to. @var list<string> */
    private array $ran = [];

    private ?string $installed = null;
    private ?string $failure = null;

    /**
     * A runner that behaves like curl/tar up to $breakAt, then misbehaves as
     * $breakAt says. TempProject-backed paths keep everything auto-cleaned.
     *
     * @return \Closure(list<string>, string, \Closure(int, string): void): void
     */
    private function scriptedRunner(TempProject $scratch, string $breakAt = ''): \Closure
    {
        return function (array $argv, string $cwd, \Closure $onExit) use ($scratch, $breakAt): void {
            $this->ran[] = implode(' ', $argv);
            $url = $argv[array_key_last($argv)];
            $curlError = 22;
            $ok = 0;
            $noOutput = '';
            $notJson = 'HTML error page';

            if ('curl' === $argv[0] && str_contains($url, 'releases/latest')) {
                match ($breakAt) {
                    'api-exit' => $onExit($curlError, $noOutput),
                    'api-json' => $onExit($ok, $notJson),
                    default => $onExit($ok, self::TAG_JSON),
                };

                return;
            }
            if ('curl' === $argv[0] && '-o' === ($argv[2] ?? '')) {
                if ('tarball-exit' === $breakAt) {
                    $onExit($curlError, $noOutput);

                    return;
                }
                if ('tarball-missing' !== $breakAt) {
                    file_put_contents($argv[3], self::TARBALL_BYTES);
                }
                $onExit($ok, $noOutput);

                return;
            }
            if ('curl' === $argv[0]) { // SHA256SUMS
                match ($breakAt) {
                    'sums-exit' => $onExit($curlError, $noOutput),
                    'sums-mismatch' => $onExit($ok, str_repeat('00', times: 32).'  symfony-lsp-v9.9.9-macos-arm64.tar.gz'),
                    default => $onExit($ok, hash('sha256', self::TARBALL_BYTES)."  symfony-lsp-v9.9.9-macos-arm64.tar.gz\n"),
                };

                return;
            }
            // tar -xzf <tarball> -C <workDir>
            if ('tar-exit' === $breakAt) {
                $tarError = 1;
                $onExit($tarError, $noOutput);

                return;
            }
            if ('tar-empty' !== $breakAt) {
                // Mirror the real archive's shape: the binary sits beside
                // license files, a subdirectory, and (on some platforms) links.
                $dir = $argv[4].'/symfony-lsp-v9.9.9-macos-arm64';
                mkdir($dir.'/THIRD_PARTY_LICENSES', recursive: true);
                file_put_contents($dir.'/symfony-lsp', data: 'the server');
                file_put_contents($dir.'/LICENSE', data: 'MIT');
                file_put_contents($dir.'/THIRD_PARTY_LICENSES/dep.txt', data: 'MIT');
                symlink($dir.'/LICENSE', $dir.'/LICENSE.link');
            }
            $onExit($ok, $noOutput);
        };
    }

    private function install(TempProject $scratch, string $breakAt = '', string $binDir = '', string $workDir = ''): void
    {
        $this->ran = [];
        $this->installed = null;
        $this->failure = null;
        $installer = new Installer(
            new Release(),
            '' === $binDir ? $scratch->path('bin') : $binDir,
            '' === $workDir ? $scratch->path('work') : $workDir,
            $this->scriptedRunner($scratch, $breakAt),
        );
        $installer->install(
            'Darwin',
            'arm64',
            function (string $path): void {
                $this->installed = $path;
            },
            function (string $why): void {
                $this->failure = $why;
            },
        );
    }

    public function testHappyPathLandsAVerifiedExecutableBinary(): void
    {
        $scratch = new TempProject();

        $this->install($scratch);

        $this->assertNull($this->failure);
        $this->assertSame($scratch->path('bin').'/symfony-lsp', $this->installed);
        $this->assertSame('the server', file_get_contents((string) $this->installed));
        $this->assertTrue(is_executable((string) $this->installed), 'the copy is chmodded runnable');
        $this->assertCount(4, $this->ran, 'api, tarball, sums, tar — in that order');
        $this->assertDirectoryDoesNotExist($scratch->path('work'), 'the ~18MB of scratch is swept once the binary landed');
        $this->assertStringContainsString('releases/latest', $this->ran[0]);
        $this->assertStringContainsString('symfony-lsp-v9.9.9-macos-arm64.tar.gz', $this->ran[1], 'the tag from the api names the asset');
        $this->assertStringContainsString('SHA256SUMS', $this->ran[2]);
        $this->assertStringStartsWith('tar -xzf', $this->ran[3]);
    }

    public function testAnUnsupportedPlatformFailsBeforeAnyProcessRuns(): void
    {
        $scratch = new TempProject();
        $this->ran = [];
        $this->failure = null;
        $installer = new Installer(new Release(), $scratch->path('bin'), $scratch->path('work'), $this->scriptedRunner($scratch));

        $installer->install(
            'Windows',
            'x86_64',
            static function (string $path): void {},
            function (string $why): void {
                $this->failure = $why;
            },
        );

        $this->assertSame('no prebuilt symfony-lsp for Windows/x86_64', $this->failure);
        $this->assertSame([], $this->ran);
    }

    public function testEachStepFailureReportsItselfAndStopsTheChain(): void
    {
        $scratch = new TempProject();

        $this->install($scratch, 'api-exit');
        $this->assertSame('could not resolve the latest symfony-lsp release', $this->failure);
        $this->assertCount(1, $this->ran);

        $this->install($scratch, 'api-json');
        $this->assertSame('could not resolve the latest symfony-lsp release', $this->failure);

        $this->install($scratch, 'tarball-exit');
        $this->assertSame('download failed: symfony-lsp-v9.9.9-macos-arm64.tar.gz', $this->failure);
        $this->assertCount(2, $this->ran);

        $this->install($scratch, 'tarball-missing');
        $this->assertSame('download failed: symfony-lsp-v9.9.9-macos-arm64.tar.gz', $this->failure, 'exit 0 with no file on disk is still a failed download');

        $this->install($scratch, 'sums-exit');
        $this->assertSame('checksum verification failed: symfony-lsp-v9.9.9-macos-arm64.tar.gz', $this->failure);

        $this->install($scratch, 'sums-mismatch');
        $this->assertSame('checksum verification failed: symfony-lsp-v9.9.9-macos-arm64.tar.gz', $this->failure);

        $this->install($scratch, 'tar-exit');
        $this->assertSame('could not extract symfony-lsp-v9.9.9-macos-arm64.tar.gz', $this->failure);

        $this->install($scratch, 'tar-empty');
        $this->assertSame('could not extract symfony-lsp-v9.9.9-macos-arm64.tar.gz', $this->failure, 'a clean tar exit without the expected member is still a failure');

        $this->assertNull($this->installed);
    }

    public function testUncreatableDirectoriesFailCleanly(): void
    {
        $scratch = new TempProject(['occupied' => 'a file where a directory must go']);

        // The work dir cannot be created under a file.
        $this->install($scratch, workDir: $scratch->path('occupied').'/work');
        $this->assertNotNull($this->failure);
        $this->assertStringStartsWith('cannot create', $this->failure);
        $this->assertSame([], $this->ran, 'nothing runs when there is nowhere to download to');

        // The bin dir cannot be created under a file: the chain runs, the landing fails.
        $this->install($scratch, binDir: $scratch->path('occupied').'/bin');
        $this->assertNotNull($this->failure);
        $this->assertStringStartsWith('cannot create', $this->failure);
        $this->assertCount(4, $this->ran);
    }

    public function testAnUnwritableTargetFailsTheCopy(): void
    {
        $scratch = new TempProject();
        mkdir($scratch->path('bin/symfony-lsp'), recursive: true); // a DIRECTORY squatting the target name

        $this->install($scratch);

        $this->assertSame('cannot write '.$scratch->path('bin').'/symfony-lsp', $this->failure);
    }

    public function testSweepingAPathThatVanishedMidRunIsANoOp(): void
    {
        // Nothing at the path at all — neither file, link nor dir. The sweep
        // must shrug, not warn: it runs inside a process-exit callback where
        // a throw would wedge the chain.
        $scratch = new TempProject();
        $installer = new Installer(new Release(), $scratch->path('bin'), $scratch->path('work'), $this->scriptedRunner($scratch));

        Probe::invoke($installer, 'removeTree', $scratch->path('never-created'));

        $this->addToAssertionCount(1);
    }

    public function testTheDefaultRunnerDrivesARealProcess(): void
    {
        // No fake: the first step spawns the real `curl`, which an empty PATH
        // turns into a spawn failure — proving the AsyncProcess default runner
        // wiring end to end without touching the network.
        putenv('PATH=/nonexistent');
        $scratch = new TempProject();
        $failure = null;
        $installer = new Installer(new Release(), $scratch->path('bin'), $scratch->path('work'));

        $installer->install(
            'Darwin',
            'arm64',
            static function (string $path): void {},
            static function (string $why) use (&$failure): void {
                $failure = $why;
            },
        );
        $this->await(static function () use (&$failure): bool {
            return null !== $failure;
        });

        $this->assertSame('could not resolve the latest symfony-lsp release', $failure);
    }
}
