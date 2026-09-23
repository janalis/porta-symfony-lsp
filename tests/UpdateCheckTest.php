<?php

declare(strict_types=1);

namespace Porta\SymfonyLsp\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\SymfonyLsp\UpdateCheck;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The throttle on the daily "there is a newer server" check.
 *
 * Every test drives an explicit $now, so nothing here depends on the wall
 * clock — a test that waited out a real day would be no test at all.
 */
final class UpdateCheckTest extends TestCase
{
    private const int NOON = 1_800_000_000;

    /**
     * The note is a MACHINE-global fact ("this machine looked today"), so it
     * sits beside the bin directory, not inside it and not in `.porta.json` —
     * a project file travels to everyone who clones the repository.
     */
    public function testTheNoteSitsBesideTheBinDirectoryNotInIt(): void
    {
        $home = new TempProject();
        $binDir = $home->path('.porta/bin');

        new UpdateCheck(new Filesystem(), $binDir)->record(self::NOON, 'v0.21.0');

        $this->assertFileExists($home->path('.porta/symfony-lsp-check.json'));
        $this->assertFileDoesNotExist($binDir.'/symfony-lsp-check.json');
        $this->assertSame(
            ['checkedAt' => self::NOON, 'latestTag' => 'v0.21.0'],
            \json_decode((string) \file_get_contents($home->path('.porta/symfony-lsp-check.json')), associative: true),
        );
    }

    /** Never looked is due, and costs exactly one request to settle. */
    public function testAMachineThatNeverLookedIsDue(): void
    {
        $home = new TempProject();

        $this->assertTrue(new UpdateCheck(new Filesystem(), $home->path('.porta/bin'))->due(self::NOON));
    }

    public function testACheckJustMadeIsNotDueAgain(): void
    {
        $home = new TempProject();
        $check = new UpdateCheck(new Filesystem(), $home->path('.porta/bin'));

        $check->record(self::NOON, 'v0.21.0');

        $this->assertFalse($check->due(self::NOON), 'the same second');
        $this->assertFalse($check->due(self::NOON + UpdateCheck::EVERY - 1), 'one second short of a day');
    }

    public function testADayLaterIsDue(): void
    {
        $home = new TempProject();
        $check = new UpdateCheck(new Filesystem(), $home->path('.porta/bin'));

        $check->record(self::NOON, 'v0.21.0');

        $this->assertTrue($check->due(self::NOON + UpdateCheck::EVERY));
    }

    /**
     * A clock that was wrong when the note was written would otherwise switch
     * the check off until it caught up — which, for a badly set clock, is
     * never.
     */
    public function testATimestampFromTheFutureIsDue(): void
    {
        $home = new TempProject();
        $check = new UpdateCheck(new Filesystem(), $home->path('.porta/bin'));

        $check->record(self::NOON + 86_400_000, 'v0.21.0');

        $this->assertTrue($check->due(self::NOON));
    }

    /**
     * Unreadable state means "never checked", which costs one request — the
     * safe direction. Trusting a corrupt timestamp would switch the feature
     * off silently and permanently.
     *
     * @return iterable<string, array{string}>
     */
    public static function unusableNotes(): iterable
    {
        yield 'not json at all' => ['not json'];
        yield 'json, but not an object' => ['42'];
        yield 'an object without the key' => ['{"latestTag":"v0.21.0"}'];
        yield 'a timestamp that is not a number' => ['{"checkedAt":"yesterday"}'];
        yield 'a float, which is not a second count we wrote' => ['{"checkedAt":1.5}'];
    }

    #[DataProvider('unusableNotes')]
    public function testAnUnusableNoteIsDue(string $contents): void
    {
        $home = new TempProject();
        \mkdir($home->path('.porta'), permissions: 0o755, recursive: true);
        \file_put_contents($home->path('.porta/symfony-lsp-check.json'), $contents);

        $this->assertTrue(new UpdateCheck(new Filesystem(), $home->path('.porta/bin'))->due(self::NOON));
    }

    /**
     * A machine that cannot write the note checks again next launch: one
     * wasted request, not a broken feature — and never a crash on a read-only
     * home.
     */
    public function testAnUnwritableHomeDoesNotThrow(): void
    {
        $home = new TempProject();
        \chmod($home->dir, permissions: 0o555);

        try {
            new UpdateCheck(new Filesystem(), $home->path('nope/bin'))->record(self::NOON, 'v0.21.0');
            $this->assertTrue(new UpdateCheck(new Filesystem(), $home->path('nope/bin'))->due(self::NOON), 'nothing was recorded, so it is still due');
        } finally {
            \chmod($home->dir, permissions: 0o755);
        }
    }
}
