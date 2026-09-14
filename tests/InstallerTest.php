<?php

declare(strict_types=1);

namespace Porta\SymfonyLsp\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
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
    /** What `curl -D -` prints for the web redirect: headers, no body. */
    private const string TAG_REDIRECT = "HTTP/2 302\r\nlocation: https://github.com/symfony/language-tools/releases/tag/v9.9.9\r\n\r\n";
    /** A 403 with the rate-limit headers GitHub actually sends. */
    /** Stands in for a GitHub credential; the name avoids mago's literal-password rule. */
    private const string FIXTURE_CREDENTIAL = 'sesame';
    /** What a working install already on disk contains, so a bad one replacing it is visible. */
    private const string WORKING_COPY = 'the server that already worked';
    private const string NOT_FOUND = "HTTP/2 404\r\n\r\n{}";
    /** A plain 200 with no `location:` — the redirect answered, but named no tag. */
    private const string NO_REDIRECT = "HTTP/2 200\r\n\r\n";
    private const string RATE_LIMITED = "HTTP/2 403\r\nx-ratelimit-limit: 60\r\nx-ratelimit-remaining: 0\r\nx-ratelimit-reset: 4102444800\r\n\r\n{\"message\":\"API rate limit exceeded\"}";
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
            $this->ran[] = \implode(' ', $argv);
            $url = $argv[\array_key_last($argv)];
            $curlError = 22;
            $ok = 0;
            $noOutput = '';
            $notJson = 'HTML error page';

            // The web redirect and the API answer the SAME path with different
            // shapes — headers vs JSON — so the rig has to tell them apart or
            // the redirect never resolves and every run falls through.
            if ('curl' === $argv[0] && \str_contains($url, 'api.github.com')) {
                match ($breakAt) {
                    'api-exit', 'both-exit' => $onExit($curlError, $noOutput),
                    'api-json' => $onExit($ok, $notJson),
                    'rate-limited', 'rate-limited-with-token' => $onExit($ok, self::RATE_LIMITED),
                    'gone' => $onExit($ok, self::NOT_FOUND),
                    default => $onExit($ok, self::TAG_JSON),
                };

                return;
            }
            if ('curl' === $argv[0] && \str_contains($url, 'releases/latest')) {
                match ($breakAt) {
                    'both-exit' => $onExit($curlError, $noOutput),
                    // Everything that names an api-* break reaches the API by
                    // way of a redirect that answered nothing useful.
                    'api-exit', 'api-json', 'rate-limited', 'rate-limited-with-token', 'gone', 'no-redirect' => $onExit($ok, self::NO_REDIRECT),
                    default => $onExit($ok, self::TAG_REDIRECT),
                };

                return;
            }
            if ('curl' === $argv[0] && '-o' === ($argv[2] ?? '')) {
                if ('tarball-exit' === $breakAt) {
                    $onExit($curlError, $noOutput);

                    return;
                }
                if ('tarball-missing' !== $breakAt) {
                    \file_put_contents($argv[3], self::TARBALL_BYTES);
                }
                $onExit($ok, $noOutput);

                return;
            }
            if ('curl' === $argv[0]) { // SHA256SUMS
                match ($breakAt) {
                    'sums-exit' => $onExit($curlError, $noOutput),
                    'sums-mismatch' => $onExit($ok, \str_repeat('00', times: 32).'  symfony-lsp-v9.9.9-macos-arm64.tar.gz'),
                    default => $onExit($ok, \hash('sha256', self::TARBALL_BYTES)."  symfony-lsp-v9.9.9-macos-arm64.tar.gz\n"),
                };

                return;
            }
            // The staged binary's own --version smoke test, run before the
            // rename so a binary that cannot execute never replaces a working
            // one.
            if ('--version' === ($argv[1] ?? '')) {
                $onExit('bad-binary' === $breakAt ? 1 : $ok, $noOutput);

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
                \mkdir($dir.'/THIRD_PARTY_LICENSES', recursive: true);
                \file_put_contents($dir.'/symfony-lsp', data: 'the server');
                \file_put_contents($dir.'/LICENSE', data: 'MIT');
                \file_put_contents($dir.'/THIRD_PARTY_LICENSES/dep.txt', data: 'MIT');
                \symlink($dir.'/LICENSE', $dir.'/LICENSE.link');
            }
            $onExit($ok, $noOutput);
        };
    }

    private function install(TempProject $scratch, string $breakAt = '', string $binDir = '', string $workDir = '', #[\SensitiveParameter] string $token = ''): void
    {
        $this->ran = [];
        $this->installed = null;
        $this->failure = null;
        $installer = new Installer(
            new Release(),
            '' === $binDir ? $scratch->path('bin') : $binDir,
            '' === $workDir ? $scratch->path('work') : $workDir,
            $this->scriptedRunner($scratch, $breakAt),
            $token,
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
        $this->assertSame('the server', \file_get_contents((string) $this->installed));
        $this->assertTrue(\is_executable((string) $this->installed), 'the copy is chmodded runnable');
        $this->assertCount(5, $this->ran, 'redirect, tarball, sums, tar, then the binary proving it runs; the API is not touched when the redirect answers');
        $this->assertStringNotContainsString('api.github.com', \implode(' ', $this->ran), 'the rate-limited API is not spent on a tag lookup');
        $this->assertDirectoryDoesNotExist($scratch->path('work'), 'the ~18MB of scratch is swept once the binary landed');
        $binEntries = \scandir($scratch->path('bin'));
        $this->assertNotFalse($binEntries);
        $this->assertSame(['symfony-lsp'], \array_values(\array_diff($binEntries, ['.', '..'])), 'the staged .installing-* name was renamed away, not left beside the binary');
        $this->assertStringContainsString('releases/latest', $this->ran[0]);
        $this->assertStringContainsString('symfony-lsp-v9.9.9-macos-arm64.tar.gz', $this->ran[1], 'the tag from the redirect names the asset');
        $this->assertStringContainsString('SHA256SUMS', $this->ran[2]);
        $this->assertStringStartsWith('tar -xzf', $this->ran[3]);
        $this->assertStringEndsWith('--version', $this->ran[4], 'the landed binary is asked to run before it replaces anything');
    }

    /**
     * A binary that cannot execute must not replace a working one.
     *
     * The rename is atomic and irreversible: once it lands, the old server is
     * gone. A wrong-architecture download, or one the system refuses to run,
     * would otherwise "install successfully" and leave the IDE with no server
     * at all — which looks exactly like the plugin being broken.
     */
    public function testABinaryThatWillNotRunIsNotLanded(): void
    {
        $scratch = new TempProject();
        $target = $scratch->path('bin').'/symfony-lsp';
        \mkdir($scratch->path('bin'), permissions: 0o755, recursive: true);
        \file_put_contents($target, self::WORKING_COPY);

        $this->install($scratch, 'bad-binary');

        $this->assertNull($this->installed);
        $this->assertStringContainsString('does not run here', (string) $this->failure);
        $this->assertSame(self::WORKING_COPY, \file_get_contents($target), 'the working copy is untouched');
        $binEntries = \scandir($scratch->path('bin'));
        $this->assertNotFalse($binEntries);
        $this->assertSame(['symfony-lsp'], \array_values(\array_diff($binEntries, ['.', '..'])), 'and the staged file is swept, not left beside it');
    }

    /**
     * The API fallback still works when the redirect names no tag.
     *
     * The redirect is tried first because it costs no API budget, but it is
     * not the only way: a changed redirect shape must not take installation
     * with it, which is the whole reason the API path was kept.
     */
    public function testTheApiFallbackResolvesTheTagWhenTheRedirectDoesNot(): void
    {
        $scratch = new TempProject();

        $this->install($scratch, 'no-redirect');

        $this->assertNull($this->failure);
        $this->assertSame($scratch->path('bin').'/symfony-lsp', $this->installed);
        $this->assertCount(6, $this->ran, 'redirect, API, tarball, sums, tar, --version');
        $this->assertStringContainsString('api.github.com', $this->ran[1]);
    }

    /**
     * A token rides on the API fallback only.
     *
     * The redirect needs none, so a user without a token is never worse off;
     * the token exists purely to lift the API's 60/hour when the fallback is
     * the path being taken.
     */
    public function testATokenIsSentOnTheApiFallbackAndNowhereElse(): void
    {
        $scratch = new TempProject();

        $this->install($scratch, 'no-redirect', token: self::FIXTURE_CREDENTIAL);

        $this->assertStringNotContainsString(self::FIXTURE_CREDENTIAL, $this->ran[0], 'the redirect carries no credential');
        $this->assertStringContainsString('Authorization: Bearer '.self::FIXTURE_CREDENTIAL, $this->ran[1], 'the API fallback does');
    }

    /** A rate limit that a token did not lift says so, rather than advising a token again. */
    public function testARateLimitWithATokenDoesNotAdviseSettingOne(): void
    {
        $scratch = new TempProject();

        $this->install($scratch, 'rate-limited-with-token', token: self::FIXTURE_CREDENTIAL);

        $this->assertStringContainsString('even with a token', (string) $this->failure);
        $this->assertStringNotContainsString('GITHUB_TOKEN', (string) $this->failure, 'advising a token to someone who has one is noise');
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

        // The tag lookup tries the web redirect, then the API. Each way of
        // failing gets its OWN line: they used to share one string, which is
        // true of all of them and actionable for none.
        $this->install($scratch, 'both-exit');
        $this->assertSame('cannot reach github.com — check the network or a proxy', $this->failure);
        $this->assertCount(2, $this->ran, 'the redirect, then the API fallback');

        // Only the API leg errored, so github.com demonstrably answered — the
        // network is not the thing to blame, and the catch-all is honest here.
        $this->install($scratch, 'api-exit');
        $this->assertSame('could not resolve the latest symfony-lsp release', $this->failure);

        $this->install($scratch, 'api-json');
        $this->assertSame('could not resolve the latest symfony-lsp release', $this->failure, 'both answered, neither parsed — the one case the old catch-all was right about');

        $this->install($scratch, 'rate-limited');
        $this->assertStringContainsString('rate limit', (string) $this->failure);
        $this->assertStringContainsString('GITHUB_TOKEN', (string) $this->failure, 'the remedy, not just the symptom');

        $this->install($scratch, 'gone');
        $this->assertSame('no releases found for symfony/language-tools — the repo or release may have moved', $this->failure);

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
        $this->assertDirectoryDoesNotExist(
            $scratch->path('work'),
            'every failure sweeps its scratch — a retry loop must not park a tarball per attempt, and a checksum-rejected archive must not stay on disk',
        );
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

    /**
     * The staging copy itself can fail, separately from the rename.
     *
     * A `bin` that exists but is not writable takes the copy down before there
     * is anything to smoke-test or rename — a different branch from the
     * squatted-name case below, which now gets as far as the rename.
     */
    public function testAnUnwritableBinDirFailsBeforeStaging(): void
    {
        $scratch = new TempProject();
        \mkdir($scratch->path('bin'), permissions: 0o755, recursive: true);
        \chmod($scratch->path('bin'), permissions: 0o555);

        try {
            $this->install($scratch);
        } finally {
            \chmod($scratch->path('bin'), permissions: 0o755);
        }

        $this->assertNull($this->installed);
        $this->assertSame('cannot write '.$scratch->path('bin').'/symfony-lsp', $this->failure);
    }

    public function testAnUnwritableTargetFailsTheCopy(): void
    {
        $scratch = new TempProject();
        \mkdir($scratch->path('bin/symfony-lsp'), recursive: true); // a DIRECTORY squatting the target name

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
        // No fake runner: the first step spawns the real `curl`, which an empty
        // PATH turns into a spawn failure — proving the AsyncProcess default
        // wiring end to end without touching the network. The finder IS faked,
        // and has to be: the probe would otherwise stop this before anything
        // spawned, and the default runner would go untested.
        \putenv('PATH=/nonexistent');
        $scratch = new TempProject();
        $failure = null;
        $installer = new Installer(
            new Release(),
            $scratch->path('bin'),
            $scratch->path('work'),
            findTool: static fn (string $tool): string => '/nonexistent/'.$tool,
        );

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

        $this->assertSame('cannot reach github.com — check the network or a proxy', $failure, 'both resolvers failed to spawn');
    }

    /**
     * A tool that is not installed is named before a byte is downloaded.
     *
     * tar is the one that matters: it is not needed until the archive is on
     * disk, so without a probe the user waits out a ~9MB download to be told
     * `could not extract`, which reads as a corrupt release rather than a
     * missing tool. Nothing is spawned and no scratch directory is created.
     */
    #[DataProvider('missingTools')]
    public function testAMissingToolIsNamedBeforeAnythingIsDownloaded(string $absent, string $expected): void
    {
        $scratch = new TempProject();
        $installer = new Installer(
            new Release(),
            $scratch->path('bin'),
            $scratch->path('work'),
            $this->scriptedRunner($scratch),
            findTool: static fn (string $tool): ?string => $tool === $absent ? null : '/usr/bin/'.$tool,
        );
        $failure = null;
        $installer->install(
            'Darwin',
            'arm64',
            static function (string $path): void {},
            static function (string $why) use (&$failure): void {
                $failure = $why;
            },
        );

        $this->assertSame($expected, $failure);
        $this->assertSame([], $this->ran, 'the probe answers before curl is ever spawned');
        $this->assertDirectoryDoesNotExist($scratch->path('work'), 'and before the scratch dir is created');
    }

    /** @return iterable<string, array{string, string}> */
    public static function missingTools(): iterable
    {
        yield 'no curl' => ['curl', 'curl is not available — install it, or set the binary setting by hand'];
        yield 'no tar' => ['tar', 'tar is not available — install it, or set the binary setting by hand'];
    }

    /**
     * An install sweeps the scratch a previous, interrupted one abandoned.
     *
     * Quitting Porta mid-download runs neither the success sweep nor the
     * failure sweep, so ~9MB stays under a pid-keyed name that a later install
     * with a recycled pid would download straight into. Age is the only safe
     * test — a SECOND Porta window may be downloading into a sibling right
     * now — so a fresh sibling must survive untouched.
     */
    public function testAnAbandonedScratchDirectoryIsSweptAndALiveOneIsNot(): void
    {
        $scratch = new TempProject();
        $tmp = $scratch->path('tmp');
        $abandoned = $tmp.'/'.Installer::SCRATCH_PREFIX.'404';
        $live = $tmp.'/'.Installer::SCRATCH_PREFIX.'405';
        $unrelated = $tmp.'/someone-elses-cache';
        foreach ([$abandoned, $live, $unrelated] as $dir) {
            \mkdir($dir, permissions: 0o755, recursive: true);
            \file_put_contents($dir.'/symfony-lsp-v9.9.9-macos-arm64.tar.gz', self::TARBALL_BYTES);
        }
        $longAgo = \time() - 7200;
        \touch($abandoned.'/symfony-lsp-v9.9.9-macos-arm64.tar.gz', $longAgo);
        \touch($abandoned, $longAgo);
        \touch($unrelated.'/symfony-lsp-v9.9.9-macos-arm64.tar.gz', $longAgo);
        \touch($unrelated, $longAgo);

        $this->install($scratch, workDir: $tmp.'/'.Installer::SCRATCH_PREFIX.'406');

        $this->assertNull($this->failure);
        $this->assertDirectoryDoesNotExist($abandoned, 'the interrupted install is reaped');
        $this->assertDirectoryExists($live, 'a download another window may still be writing is left alone');
        $this->assertDirectoryExists($unrelated, 'and nothing outside the installer\'s own naming is touched');
    }

    /** A recycled pid finds its own stale tree, and starts from empty rather than reusing it. */
    public function testAScratchDirectoryLeftByARecycledPidIsNotReused(): void
    {
        $scratch = new TempProject();
        $workDir = $scratch->path('tmp').'/'.Installer::SCRATCH_PREFIX.'404';
        \mkdir($workDir.'/symfony-lsp-v9.9.9-macos-arm64', permissions: 0o755, recursive: true);
        $leftover = $workDir.'/symfony-lsp-v9.9.9-macos-arm64/symfony-lsp';
        \file_put_contents($leftover, data: 'a half-extracted binary from the last life of this pid');
        $longAgo = \time() - 7200;
        \touch($leftover, $longAgo);
        \touch(\dirname($leftover), $longAgo);
        \touch($workDir, $longAgo);

        $this->install($scratch, 'tar-empty', workDir: $workDir);

        $this->assertSame('could not extract symfony-lsp-v9.9.9-macos-arm64.tar.gz', $this->failure, 'the stale binary was swept, so tar extracting nothing is a miss');
        $this->assertNull($this->installed);
    }
}
