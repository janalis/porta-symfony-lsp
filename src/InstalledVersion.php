<?php

declare(strict_types=1);

namespace Porta\SymfonyLsp;

/**
 * What version of the server is on this machine — asked of the BINARY, cached
 * in memory, never written to disk.
 *
 * A sidecar file or a setting would be a second source of truth that desyncs
 * the moment someone swaps the binary by hand, and a setting would put a
 * machine-global fact into a file that travels with the project. The binary
 * can answer for itself in ~50ms, once per session, so it does.
 *
 * {@see known()} is the only thing a render path may call: it returns the
 * cache and never spawns. {@see probe()} is what fills it, and must be reached
 * from an action or an `onReady` hook.
 */
final class InstalledVersion
{
    /** `--version` prints one line and exits; there is nothing to watch arrive. */
    private const null NO_CHUNKS = null;

    private string $version = '';

    /** The path {@see $version} describes, so a reinstall elsewhere re-probes. */
    private string $probed = '';

    private bool $probing = false;

    /** @var \Closure(list<string>, string, \Closure(int, string): void, (\Closure(string): void)|null): void */
    private readonly \Closure $run;

    /**
     * @param (\Closure(list<string>, string, \Closure(int, string): void, (\Closure(string): void)|null): void)|null $run
     *        inject a fake to test without processes
     */
    public function __construct(
        private readonly Release $release,
        ?\Closure $run = null,
    ) {
        $this->run = $run ?? Runner::default(Runner::PROBE_TIMEOUT);
    }

    /**
     * The cached version, or '' when nothing is known yet — no binary, the
     * probe has not answered, or the binary names itself in some other shape.
     *
     * Cheap and throw-free by contract: the status segment and the settings
     * row read it once per render.
     */
    public function known(): string
    {
        return $this->version;
    }

    /**
     * Record what an install just reported, so the freshly landed binary does
     * not have to be asked again — {@see Installer} already ran `--version` on
     * it as the smoke test.
     */
    public function remember(string $path, string $version): void
    {
        $this->probed = $path;
        $this->version = $version;
    }

    /**
     * Ask $binary what it is, unless that same path has already answered.
     *
     * A null binary forgets rather than keeping a version for a server that is
     * no longer there. Concurrent calls collapse: the second is dropped, not
     * queued, because both would ask the same question of the same file.
     *
     * @param (\Closure(): void)|null $then runs once the cache has moved (or has been left alone)
     */
    public function probe(?string $binary, ?\Closure $then = null): void
    {
        if (null === $binary) {
            $this->probed = '';
            $this->version = '';
            $then?->__invoke();

            return;
        }
        if ($this->probing || $binary === $this->probed && '' !== $this->version) {
            $then?->__invoke();

            return;
        }
        $this->probing = true;
        // The binary's own directory as the cwd: a bare name resolves through
        // the PATH either way, and a pathed one does not care — but the cwd
        // must exist, and the project root is the one directory that can be
        // deleted underneath a long-lived IDE.
        $cwd = \str_contains($binary, needle: '/') ? \dirname($binary) : \sys_get_temp_dir();
        ($this->run)(
            [$binary, '--version'],
            $cwd,
            function (int $code, string $reported) use ($binary, $then): void {
                $this->probing = false;
                $this->probed = $binary;
                // A binary that will not run leaves the version unknown rather
                // than stale: the caller's next question is "can I update this?",
                // and answering it from a version the file no longer has is worse
                // than answering "I do not know".
                $this->version = 0 === $code ? $this->release->versionFrom($reported) ?? '' : '';
                $then?->__invoke();
            },
            self::NO_CHUNKS,
        );
    }
}
