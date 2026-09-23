<?php

declare(strict_types=1);

namespace Porta\SymfonyLsp;

use Porta\Support\JsonFile;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Whether it is time to ask GitHub about a newer release, and the note it
 * leaves behind once it has.
 *
 * A once-per-launch check would cost a request every time a window opens —
 * several a day on a normal machine, all to learn the same thing — and on a
 * heavy day it is the IP rate limit the install already ran into. Once a day
 * is what a release cadence measured in weeks actually needs.
 *
 * The note lives beside the BINARY's directory, never in `.porta.json`: when
 * this machine last looked at a GitHub page is a machine-global fact, and a
 * project file travels to everyone who clones the repository. It is the same
 * argument that removed the `binary: ''` write.
 */
final readonly class UpdateCheck
{
    public const int EVERY = 86_400;

    private string $file;

    /** @param string $binDir where the server is installed — the state sits beside it, not in it */
    public function __construct(
        private Filesystem $filesystem,
        string $binDir,
    ) {
        $this->file = \dirname($binDir).'/symfony-lsp-check.json';
    }

    /**
     * Whether a check is owed at $now.
     *
     * A missing, unreadable or malformed note means "never checked", which
     * costs one request — the safe direction, unlike trusting a corrupt
     * timestamp and never checking again.
     *
     * A timestamp in the FUTURE is also due: a clock that was wrong when the
     * note was written would otherwise switch the feature off until it caught
     * up, which for a badly set clock is never.
     */
    public function due(int $now): bool
    {
        $state = JsonFile::read($this->file);
        /** @var mixed $checkedAt */
        $checkedAt = $state['checkedAt'] ?? null;

        return !\is_int($checkedAt) || $checkedAt > $now || ($now - $checkedAt) >= self::EVERY;
    }

    /**
     * Note that $tag was the latest release at $now. Best-effort: a machine
     * that cannot write here checks again next launch, which is a wasted
     * request and not a broken feature.
     */
    public function record(int $now, string $tag): void
    {
        JsonFile::write($this->filesystem, $this->file, ['checkedAt' => $now, 'latestTag' => $tag]);
    }
}
