<?php

declare(strict_types=1);

namespace Porta\SymfonyLsp;

use Porta\Support\AsyncProcess;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Downloads the symfony-lsp release binary for this machine: resolve the
 * latest tag from GitHub, fetch the platform archive, verify it against the
 * release's SHA256SUMS, extract, and land the binary at `<binDir>/symfony-lsp`.
 * Every step is asynchronous (curl/tar through $run, {@see AsyncProcess} by
 * default), so the UI never blocks on the network.
 *
 * Exactly one of $onSuccess / $onFailure fires, once — the caller's
 * single-flight flag is released in whichever it is.
 */
final class Installer
{
    /**
     * The scratch directory's name, minus the per-process suffix the caller
     * appends. Shared with the caller so the sweep in {@see reapStaleScratch()}
     * recognises the directories this class itself creates, and only those.
     */
    public const string SCRATCH_PREFIX = 'symfony-lsp-install-';

    /**
     * How old an abandoned scratch directory must be before a later install
     * sweeps it. Comfortably past every step's own ceiling
     * ({@see AsyncProcess::EXTERNAL_TOOL_TIMEOUT}, 300s), because the one
     * thing this must never do is delete a download a SECOND Porta window is
     * still running.
     */
    private const int STALE_AFTER = 3600;

    /** The external tools every install shells out to, in the order they are needed. */
    private const array TOOLS = ['curl', 'tar'];

    /** @var \Closure(list<string>, string, \Closure(int, string): void): void */
    private readonly \Closure $run;

    /** @var \Closure(string): ?string */
    private readonly \Closure $findTool;

    /**
     * Two docblocks used to sit here, and PHP reads only the last: everything
     * said about $binDir, $workDir and $run was invisible to every tool.
     *
     * @param string $binDir the installed binary's home (created if missing)
     * @param string $workDir scratch space for the download and extraction; the
     *        name must start with {@see SCRATCH_PREFIX} so an abandoned one is
     *        recognisable to the next install's sweep
     * @param (\Closure(list<string>, string, \Closure(int, string): void): void)|null $run
     *        (argv, cwd, onExit(exit code, stdout)) — inject a fake to test without processes
     * @param string $token a GitHub token for the API fallback, or '' for none.
     *                      Never logged, never toasted — it only ever reaches
     *                      curl's argv.
     * @param (\Closure(string): ?string)|null $findTool resolves an external tool on the
     *        PATH — inject a fake to test a machine without curl or without tar
     */
    public function __construct(
        private readonly Release $release,
        private readonly string $binDir,
        private readonly string $workDir,
        ?\Closure $run = null,
        #[\SensitiveParameter]
        private readonly string $token = '',
        ?\Closure $findTool = null,
    ) {
        $this->findTool = $findTool ?? static fn (string $tool): ?string => new ExecutableFinder()->find($tool);
        $this->run = $run ?? /** @param list<string> $argv */ static function (array $argv, string $cwd, \Closure $onExit): void {
            // start() returning false means nothing was spawned and no
            // callback will ever fire — report it as a failed exit instead of
            // leaving the chain (and the caller's busy flag) hanging forever.
            if (!new AsyncProcess($argv, $cwd, timeout: AsyncProcess::EXTERNAL_TOOL_TIMEOUT)->start($onExit)) {
                $noOutput = '';
                $onExit(-1, $noOutput);
            }
        };
    }

    /**
     * @param \Closure(string): void $onSuccess receives the installed binary's path
     * @param \Closure(string): void $onFailure receives what went wrong, flash-sized
     */
    public function install(string $osFamily, string $machine, \Closure $onSuccess, \Closure $onFailure): void
    {
        // Every failure sweeps the scratch too: a retried install must not
        // park another ~18MB per attempt, and a checksum-rejected archive
        // must not stay on disk at a predictable path.
        $onFailure = function (string $why) use ($onFailure): void {
            $this->removeTree($this->workDir);
            $onFailure($why);
        };
        $platform = $this->release->platform($osFamily, $machine);
        if (null === $platform) {
            $onFailure('no prebuilt symfony-lsp for '.$osFamily.'/'.$machine);

            return;
        }
        // Probe BOTH tools before a byte moves. tar is not needed until the
        // archive is on disk, and finding out then means the user waited for a
        // ~9MB download to be told `could not extract` — which reads as a
        // corrupt release rather than a missing tool.
        $missing = $this->missingTool();
        if (null !== $missing) {
            $onFailure($missing.' is not available — install it, or set the binary setting by hand');

            return;
        }
        $this->reapStaleScratch();
        // @mago-expect lint:no-error-control-operator -- a blocked scratch dir is an expected failure path; its warning must reach the flash, not the error log.
        if (!is_dir($this->workDir) && !@mkdir($this->workDir, permissions: 0o755, recursive: true)) {
            $onFailure('cannot create '.$this->workDir);

            return;
        }

        $this->resolveTag(function (?string $tag, string $why) use ($platform, $onSuccess, $onFailure): void {
            if (null === $tag) {
                $onFailure($why);

                return;
            }
            $this->download($tag, $platform, $onSuccess, $onFailure);
        });
    }

    /** The first of {@see TOOLS} that is not on the PATH, or null when both are. */
    private function missingTool(): ?string
    {
        foreach (self::TOOLS as $tool) {
            if (null === ($this->findTool)($tool)) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * Delete abandoned scratch directories beside this install's own.
     *
     * Nothing else ever reaps them. A completed install sweeps its own, and so
     * does every failure — but quitting Porta mid-download does neither, and
     * leaves the ~9MB tarball behind under a name keyed on the pid. Pids are
     * recycled, so that leftover is also the tree the NEXT install with the
     * same pid would download into.
     *
     * Age is the only safe test: a second Porta window may be downloading into
     * a sibling right now, and its tarball's mtime moves as curl writes. So a
     * directory counts as abandoned only when nothing in it has been touched
     * for {@see STALE_AFTER} — far longer than any step is allowed to take.
     */
    private function reapStaleScratch(): void
    {
        $parent = \dirname($this->workDir);
        $entries = is_dir($parent) ? scandir($parent) : false;
        foreach (false === $entries ? [] : $entries as $entry) {
            $path = $parent.'/'.$entry;
            if (!str_starts_with($entry, self::SCRATCH_PREFIX) || !is_dir($path) || self::touchedRecently($path)) {
                continue;
            }

            $this->removeTree($path);
        }
    }

    /** Whether $dir or anything directly in it was written to within {@see STALE_AFTER}. */
    private static function touchedRecently(string $dir): bool
    {
        $cutoff = time() - self::STALE_AFTER;
        $newest = (int) filemtime($dir);
        $entries = scandir($dir);
        foreach (false === $entries ? [] : $entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $newest = max($newest, (int) filemtime($dir.'/'.$entry));
        }

        return $newest > $cutoff;
    }

    /**
     * The latest release tag, from the web redirect first and the API second.
     *
     * The redirect (`github.com/<repo>/releases/latest` → `…/releases/tag/vX`)
     * is ordinary release traffic on the host the download already uses. The
     * API is 60 requests per hour per IP unauthenticated, and spending that
     * budget on a tag lookup is what made this command fail with `could not
     * resolve the latest symfony-lsp release` while nothing was wrong — a 403
     * whose real remedy (wait, or set a token) it never named.
     *
     * `-D -` puts the headers on stdout, and no `-f`: a `-f` turns every
     * non-2xx into one exit code and throws the body away, which is precisely
     * the blindness being fixed. The API keeps `-f` because its answer is JSON
     * the parser must not be fed garbage.
     *
     * @param \Closure(?string, string): void $onTag tag, or null with a reason
     */
    private function resolveTag(\Closure $onTag): void
    {
        ($this->run)(['curl', '-sSL', '-o', '/dev/null', '-D', '-', $this->release->latestUrl()], $this->workDir, function (int $code, string $headers) use ($onTag): void {
            $tag = 0 === $code ? $this->release->tagFromLocation($headers) : null;
            if (null !== $tag) {
                $noReason = '';
                $onTag($tag, $noReason);

                return;
            }
            $this->resolveTagFromApi($code, $onTag);
        });
    }

    /**
     * The API fallback, used only when the redirect did not answer.
     *
     * @param int                             $redirectCode the web attempt's exit code, so a total
     *                                                      network failure is not blamed on GitHub
     * @param \Closure(?string, string): void $onTag
     */
    private function resolveTagFromApi(int $redirectCode, \Closure $onTag): void
    {
        $argv = ['curl', '-sS', '-D', '-', $this->release->apiUrl()];
        if ('' !== $this->token) {
            array_splice($argv, offset: 1, length: 0, replacement: ['-H', 'Authorization: Bearer '.$this->token]);
        }

        ($this->run)($argv, $this->workDir, function (int $code, string $response) use ($redirectCode, $onTag): void {
            $tag = 0 === $code ? $this->release->latestTag(self::body($response)) : null;
            if (null !== $tag) {
                $noReason = '';
                $onTag($tag, $noReason);

                return;
            }
            $onTag(null, self::why($code, $redirectCode, $response, $this->token));
        });
    }

    /**
     * What to tell the user, in a flash-sized line.
     *
     * Every one of these used to be `could not resolve the latest symfony-lsp
     * release`, which is true of all of them and useful for none.
     */
    private static function why(int $code, int $redirectCode, string $response, #[\SensitiveParameter] string $token): string
    {
        // A missing curl no longer arrives here: install() probes the PATH
        // before it spawns anything, so both resolvers failing now means the
        // host did not answer — a timeout, a refused connection, a proxy.
        if (0 !== $code && 0 !== $redirectCode) {
            return 'cannot reach github.com — check the network or a proxy';
        }
        $headers = self::headers($response);
        if (str_contains($headers, '403') && preg_match('~^x-ratelimit-remaining:\s*0~mi', $headers)) {
            return '' !== $token
                ? 'GitHub rate limit reached even with a token — try again shortly'
                : 'GitHub rate limit reached (60/hour per IP) — set GITHUB_TOKEN, or retry in '.self::minutesUntilReset($headers).' min';
        }
        if (str_contains($headers, '404')) {
            return 'no releases found for symfony/language-tools — the repo or release may have moved';
        }

        return 'could not resolve the latest symfony-lsp release';
    }

    /** Whole minutes until `x-ratelimit-reset`, floored at 1 so it never reads "0 min". */
    private static function minutesUntilReset(string $headers): int
    {
        $matches = [];

        return 1 === preg_match('~^x-ratelimit-reset:\s*([0-9]+)~mi', $headers, $matches)
            ? max(1, (int) ceil(((int) $matches[1] - time()) / 60))
            : 1;
    }

    /** The header block of a `-D -` response — everything before the first blank line. */
    private static function headers(string $response): string
    {
        $end = strpos($response, needle: "\r\n\r\n");

        return false === $end ? $response : substr($response, offset: 0, length: $end);
    }

    /** The body of a `-D -` response — everything after the last header block. */
    private static function body(string $response): string
    {
        $end = strrpos($response, needle: "\r\n\r\n");

        return false === $end ? $response : substr($response, $end + 4);
    }

    /** @param \Closure(string): void $onSuccess @param \Closure(string): void $onFailure */
    private function download(string $tag, string $platform, \Closure $onSuccess, \Closure $onFailure): void
    {
        $asset = $this->release->asset($tag, $platform);
        $tarball = $this->workDir.'/'.$asset;

        ($this->run)(['curl', '-fsSL', '-o', $tarball, $this->release->assetUrl($tag, $asset)], $this->workDir, function (int $code) use ($tag, $asset, $tarball, $onSuccess, $onFailure): void {
            if (0 !== $code || !is_file($tarball)) {
                $onFailure('download failed: '.$asset);

                return;
            }
            ($this->run)(['curl', '-fsSL', $this->release->sumsUrl($tag)], $this->workDir, function (int $code, string $sums) use ($asset, $tarball, $onSuccess, $onFailure): void {
                $expected = 0 === $code ? $this->release->expectedSha($sums, $asset) : null;
                if (null === $expected || hash_file('sha256', $tarball) !== $expected) {
                    $onFailure('checksum verification failed: '.$asset);

                    return;
                }
                $this->extract($asset, $tarball, $onSuccess, $onFailure);
            });
        });
    }

    /** @param \Closure(string): void $onSuccess @param \Closure(string): void $onFailure */
    private function extract(string $asset, string $tarball, \Closure $onSuccess, \Closure $onFailure): void
    {
        ($this->run)(['tar', '-xzf', $tarball, '-C', $this->workDir], $this->workDir, function (int $code) use ($asset, $onSuccess, $onFailure): void {
            // The archive holds `<asset minus .tar.gz>/symfony-lsp`.
            $extracted = $this->workDir.'/'.substr($asset, offset: 0, length: -\strlen('.tar.gz')).'/symfony-lsp';
            if (0 !== $code || !is_file($extracted)) {
                $onFailure('could not extract '.$asset);

                return;
            }
            // @mago-expect lint:no-error-control-operator -- an unwritable destination is an expected failure path; its warning must reach the flash, not the error log.
            if (!is_dir($this->binDir) && !@mkdir($this->binDir, permissions: 0o755, recursive: true)) {
                $onFailure('cannot create '.$this->binDir);

                return;
            }
            // copy into binDir first (the scratch dir and the destination are
            // routinely on different filesystems, so a direct rename could
            // not cross), then rename over the target: same-filesystem rename
            // is atomic and replaces a BUSY file — overwriting a running
            // server's binary in place fails with ETXTBSY on Linux and can
            // hand a racing relaunch a torn executable.
            $target = $this->binDir.'/symfony-lsp';
            $staged = $target.'.installing-'.(int) getmypid();
            // @mago-expect lint:no-error-control-operator -- same: a squatted target name is reported through onFailure.
            $copied = @copy($extracted, $staged);
            // @mago-expect lint:no-error-control-operator -- same: a squatted target name is reported through onFailure.
            $prepared = $copied && @chmod($staged, permissions: 0o755);
            if (!$prepared) {
                $this->removeTree($staged);
                $onFailure('cannot write '.$target);

                return;
            }
            // Run it BEFORE the rename, never after. The rename replaces a
            // working server, so a binary that cannot execute — wrong
            // architecture, a truncated download that still matched a stale
            // checksum, a Gatekeeper refusal — must be caught while the old
            // copy is still the one on disk. Landing it and reporting success
            // is how an install "succeeds" and leaves an IDE with no server.
            ($this->run)([$staged, '--version'], $this->workDir, function (int $code) use ($staged, $target, $onSuccess, $onFailure): void {
                if (0 !== $code) {
                    $this->removeTree($staged);
                    $onFailure('the downloaded symfony-lsp does not run here — wrong architecture, or blocked by the system');

                    return;
                }
                // @mago-expect lint:no-error-control-operator -- a squatted target name is reported through onFailure.
                if (!@rename($staged, $target)) {
                    $this->removeTree($staged);
                    $onFailure('cannot write '.$target);

                    return;
                }
                $this->removeTree($this->workDir);
                $onSuccess($target);
            });
        });
    }

    /**
     * Sweep the whole scratch dir the install left behind — the ~18MB tarball
     * plus the extracted archive (which also carries license files and
     * subdirectories, so this must recurse). Best effort, throw-free: a
     * leftover scratch file is not worth failing a completed install over,
     * and an exception here would die inside a process-exit callback.
     */
    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            // @mago-expect lint:no-error-control-operator -- best-effort sweep; an unremovable leftover just stays.
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        foreach (false === $entries ? [] : $entries as $entry) {
            if (!('.' !== $entry && '..' !== $entry)) {
                continue;
            }

            $this->removeTree($path.'/'.$entry);
        }
        // @mago-expect lint:no-error-control-operator -- best-effort sweep; an unremovable leftover just stays.
        @rmdir($path);
    }
}
