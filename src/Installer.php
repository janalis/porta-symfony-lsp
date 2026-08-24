<?php

declare(strict_types=1);

namespace Porta\SymfonyLsp;

use Porta\Support\AsyncProcess;

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
    /** @var \Closure(list<string>, string, \Closure(int, string): void): void */
    private readonly \Closure $run;

    /**
     * @param string $binDir the installed binary's home (created if missing)
     * @param string $workDir scratch space for the download and extraction
     * @param (\Closure(list<string>, string, \Closure(int, string): void): void)|null $run
     *        (argv, cwd, onExit(exit code, stdout)) — inject a fake to test without processes
     */
    public function __construct(
        private readonly Release $release,
        private readonly string $binDir,
        private readonly string $workDir,
        ?\Closure $run = null,
    ) {
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
        $platform = $this->release->platform($osFamily, $machine);
        if (null === $platform) {
            $onFailure('no prebuilt symfony-lsp for '.$osFamily.'/'.$machine);

            return;
        }
        // @mago-expect lint:no-error-control-operator -- a blocked scratch dir is an expected failure path; its warning must reach the flash, not the error log.
        if (!is_dir($this->workDir) && !@mkdir($this->workDir, permissions: 0o755, recursive: true)) {
            $onFailure('cannot create '.$this->workDir);

            return;
        }

        ($this->run)(['curl', '-fsSL', $this->release->apiUrl()], $this->workDir, function (int $code, string $json) use ($platform, $onSuccess, $onFailure): void {
            $tag = 0 === $code ? $this->release->latestTag($json) : null;
            if (null === $tag) {
                $onFailure('could not resolve the latest symfony-lsp release');

                return;
            }
            $this->download($tag, $platform, $onSuccess, $onFailure);
        });
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
            // copy + chmod, not rename: the scratch dir and the destination
            // are routinely on different filesystems (tmpfs vs home).
            $target = $this->binDir.'/symfony-lsp';
            // @mago-expect lint:no-error-control-operator -- same: a squatted target name is reported through onFailure.
            $copied = @copy($extracted, $target);
            // @mago-expect lint:no-error-control-operator -- same: a squatted target name is reported through onFailure.
            if (!$copied || !@chmod($target, permissions: 0o755)) {
                $onFailure('cannot write '.$target);

                return;
            }
            $this->removeTree($this->workDir);
            $onSuccess($target);
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
        foreach (scandir($path) ?: [] as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                $this->removeTree($path.'/'.$entry);
            }
        }
        // @mago-expect lint:no-error-control-operator -- best-effort sweep; an unremovable leftover just stays.
        @rmdir($path);
    }
}
