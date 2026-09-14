<?php

declare(strict_types=1);

namespace Porta\SymfonyLsp\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\NavigatesSettings;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The symfony-lsp bundled plugin through a real IDE: it serves the languages
 * its `languages` setting claims, the launch carries the settings-derived
 * `initializationOptions` (workspaceTrust always explicit), the Settings page
 * lists its rows WITHOUT an install offer (the binary is a GitHub Releases
 * download, not a package), and flipping runtime-indexing relaunches the
 * server live with trust granted.
 *
 * The "binary" is a shell stub wrapping the logging mock server, so the
 * asserts read the exact initialize payload off the wire log.
 */
final class IdeSymfonyLspPluginTest extends TestCase
{
    use ClearsPortaLsp;
    use NavigatesSettings;
    use PreservesPath;
    use RunsIde;
    use UsesBundledPlugins;

    private ?string $savedPortaHome = null;

    protected function setUp(): void
    {
        \putenv('PATH=/nonexistent');
        $home = \getenv('PORTA_HOME');
        $this->savedPortaHome = false === $home ? null : $home;
    }

    protected function tearDown(): void
    {
        \putenv('PORTA_HOME'.(null === $this->savedPortaHome ? '' : '='.$this->savedPortaHome));
        \putenv('FAKE_TARBALL');
    }

    /**
     * A project with the plugin enabled and its binary pointed at a stub that
     * execs the logging mock LSP server, appending every message to $logName.
     *
     * @param array<string, string|array<string, mixed>> $settings extra plugins.symfony-lsp.* settings
     * @param array<string, string>                      $files
     */
    private static function project(string $logName, array $settings = [], array $files = []): TempProject
    {
        $project = new TempProject($files);
        $server = \dirname(__DIR__, levels: 3).'/tests/Support/logging-lsp-server.php';
        $stub = "#!/bin/sh\nexec \"".\PHP_BINARY."\" \"{$server}\" \"{$project->path($logName)}\"\n";
        $binary = $project->path('symfony-lsp-stub');
        \file_put_contents($binary, $stub);
        \chmod($binary, permissions: 0o755);
        \file_put_contents(
            $project->path('.porta.json'),
            \json_encode([
                'enabledPlugins' => ['symfony-lsp'],
                'plugins' => ['symfony-lsp' => ['binary' => $binary, ...$settings]],
            ]),
        );

        return $project;
    }

    /**
     * The `initializationOptions` of every `initialize` request the stub
     * logged so far, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    private static function initializeOptions(string $log): array
    {
        if (!\is_file($log)) {
            return [];
        }
        $options = [];
        foreach (\array_filter(\explode("\n", (string) \file_get_contents($log))) as $line) {
            /** @var array{method?: string, params?: array{initializationOptions?: array<string, mixed>}}|null $message */
            $message = \json_decode($line, associative: true);
            if (\is_array($message) && 'initialize' === ($message['method'] ?? null)) {
                $options[] = $message['params']['initializationOptions'] ?? [];
            }
        }

        return $options;
    }

    public function testServesTwigWithTheConfiguredOptionsAndTogglingRuntimeIndexingRelaunches(): void
    {
        $project = self::project('lsp.log', files: ['t.twig' => "{{ app.user }}\n"]);
        $log = $project->path('lsp.log');

        $this->runIde(
            $project,
            $project->path('t.twig'),
            [
                300 => function ($tui, $terminal) use ($log): void {
                    // The twig buffer's didOpen launches the server; the wire
                    // log is the on-disk effect to await, not the status bar.
                    $this->await(static fn (): bool => [] !== self::initializeOptions($log), what: 'the first initialize to reach the wire log');
                    $this->assertSame(
                        ['phpCommand' => ['php'], 'environment' => 'dev', 'runtimeIndexing' => false, 'workspaceTrust' => false],
                        self::initializeOptions($log)[0],
                        'the defaults travel as initializationOptions, workspaceTrust explicit',
                    );
                    $this->awaitPainted($tui, $terminal, 'LSP:symfony-lsp');
                    $this->assertStringContainsString('LSP:symfony-lsp', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the status bar names the plugin server');
                },
                500 => function ($tui, $terminal, $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $tui->handleInput("\t"); // form → nav
                    $this->selectSettingsSection($tui->getById('settings-nav'), 'plugin:symfony-lsp');
                    $this->awaitPainted($tui, $terminal, 'symfony-lsp binary');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('symfony-lsp binary', $screen);
                    $this->assertStringContainsString('Runtime indexing', $screen);
                    $this->assertStringNotContainsString('Install symfony-lsp', $screen, 'a GitHub Releases download has no install offer');

                    // Enabled → binary → languages → runtime-indexing, then toggle.
                    $tui->handleInput("\t"); // nav → form
                    $tui->handleInput("\x1b[A"); // clamp to the Enabled row
                    $tui->handleInput("\x1b[A");
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\r");
                },
                800 => function ($tui, $terminal) use ($log): void {
                    // The setting edit reconfigures LSP live: the relaunched
                    // server's initialize now grants workspace trust.
                    $this->await(static fn (): bool => \count(self::initializeOptions($log)) >= 2, what: 'the relaunched server\'s second initialize');
                    $options = self::initializeOptions($log)[1];
                    $this->assertSame(true, $options['runtimeIndexing'] ?? null, 'the toggled setting reached the relaunch');
                    $this->assertSame(true, $options['workspaceTrust'] ?? null, 'runtime indexing grants trust');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    /**
     * curl/tar stand-ins on a stubbed PATH: `curl` serves a fixture "release"
     * (latest-tag JSON, the tarball from $FAKE_TARBALL, a SHA256SUMS whose
     * hashes really match it), and `tar` "extracts" the expected member — a
     * runnable script wrapping the mock LSP server, so the post-install
     * relaunch has something real to talk to.
     */
    private static function stubDownloadTools(TempProject $project, string $logName = ''): void
    {
        // With $logName the landed server LOGS every message, so a test can
        // count `initialize`s — one per launch, which is how a RELAUNCH is
        // observed at all.
        $mock = '' === $logName
            ? \dirname(__DIR__, levels: 3).'/tests/Support/mock-lsp-server.php'
            : \dirname(__DIR__, levels: 3).'/tests/Support/logging-lsp-server.php';
        $logArg = '' === $logName ? '' : ' \\"'.$project->path($logName).'\\"';
        $curl = <<<'PHP'
            $args = array_slice($argv, 1);
            $out = null;
            foreach ($args as $i => $a) {
                if ('-o' === $a) {
                    $out = $args[$i + 1];
                }
            }
            $url = $args[array_key_last($args)];
            if (null !== $out) {
                copy((string) getenv('FAKE_TARBALL'), $out);
                exit(0);
            }
            if (str_contains($url, 'releases/latest')) {
                echo '{"tag_name":"v9.9.9"}';
                exit(0);
            }
            $hash = hash_file('sha256', (string) getenv('FAKE_TARBALL'));
            foreach (['macos-arm64', 'macos-x64', 'linux-arm64', 'linux-x64'] as $platform) {
                echo "{$hash}  symfony-lsp-v9.9.9-{$platform}.tar.gz\n";
            }
            exit(0);
            PHP;
        $tar = <<<PHP
            // tar -xzf <tarball> -C <dir>
            \$dir = \$argv[4].'/'.basename(\$argv[2], '.tar.gz');
            mkdir(\$dir, 0o755, true);
            file_put_contents(\$dir.'/symfony-lsp', "#!/bin/sh\\nif [ \\"\\$1\\" = --version ]; then echo 'Symfony Language Tools 9.9.9'; exit 0; fi\\nexec \\"".PHP_BINARY."\\" \\"{$mock}\\"{$logArg}\\n");
            exit(0);
            PHP;
        foreach (['curl' => $curl, 'tar' => $tar] as $name => $body) {
            $path = $project->path('stubs/'.$name);
            \file_put_contents($path, '#!'.\PHP_BINARY."\n<?php\n".$body."\n");
            \chmod($path, permissions: 0o755);
        }
        \file_put_contents($project->path('fake.tar.gz'), data: 'opaque bytes standing in for the release archive');
        \putenv('FAKE_TARBALL='.$project->path('fake.tar.gz'));
        \putenv('PATH='.$project->path('stubs'));
    }

    /**
     * There is an Update command, and it installs.
     *
     * The menu can only GREY an entry, never hide it, so the single "Install
     * Server" sat there unchanged after installing — reading as though nothing
     * had happened — and there was no update affordance at all: re-running
     * install WAS the update, which nobody could be expected to guess. Two
     * commands now share one action, each shown when it applies.
     */
    public function testUpdateIsItsOwnCommandAndPerformsTheInstall(): void
    {
        $home = new TempProject();
        \putenv('PORTA_HOME='.$home->dir);
        $project = new TempProject([
            't.twig' => "x\n",
            'stubs/.keep' => '',
            '.porta.json' => \json_encode(['enabledPlugins' => ['symfony-lsp']]),
        ]);
        self::stubDownloadTools($project);
        $installed = $home->dir.'/.porta/bin/symfony-lsp';

        $this->runIde(
            $project,
            $project->path('t.twig'),
            [
                300 => function ($tui, $terminal, $ide) use ($installed): void {
                    $registry = Services::resolve($ide, CommandRegistry::class);
                    $this->assertTrue($registry->has('symfony-lsp:install'));
                    $this->assertTrue($registry->has('symfony-lsp:update'), 'an update affordance exists at all');
                    $this->assertFileDoesNotExist($installed);

                    // The update command is not a stub: it drives the same
                    // download the install command does.
                    $registry->run('symfony-lsp:update');
                    $this->awaitPainted($tui, $terminal, 'symfony-lsp installed:');
                    $this->assertFileExists($installed);
                },
            ],
        );
    }

    /**
     * A SECOND install relaunches the server — the upgrade case.
     *
     * The first install changes the resolved map (with no binary the plugin
     * resolved no command at all), so it relaunches whatever the mechanism.
     * An upgrade does not: the new server lands at the same path, so the argv
     * is byte-identical, `LspSessionPool::reconfigure()`'s identical-map skip
     * declines to act, and the old process keeps answering from the inode
     * `rename()` already unlinked — while the toast says it installed.
     *
     * Counted in `initialize` messages, one per launch. Written because a
     * mutation swapping the forced relaunch back to the skipping path passed
     * every other test in the suite: nothing here covered a second install.
     */
    public function testASecondInstallRelaunchesTheRunningServer(): void
    {
        $home = new TempProject();
        \putenv('PORTA_HOME='.$home->dir);
        $project = new TempProject([
            't.twig' => "x\n",
            'stubs/.keep' => '',
            '.porta.json' => \json_encode(['enabledPlugins' => ['symfony-lsp']]),
        ]);
        self::stubDownloadTools($project, 'lsp.log');
        $log = $project->path('lsp.log');
        $launches = 0;

        $this->runIde(
            $project,
            $project->path('t.twig'),
            [
                300 => function ($tui, $terminal, $ide): void {
                    $this->awaitPainted($tui, $terminal, 'LSP:no server');
                    Services::resolve($ide, CommandRegistry::class)->run('symfony-lsp:install');
                },
                800 => function ($tui, $terminal, $ide) use ($log, &$launches): void {
                    $this->awaitPainted($tui, $terminal, 'symfony-lsp installed:');
                    $this->await(static fn (): bool => [] !== self::initializeOptions($log), what: 'the freshly installed server to launch');
                    $launches = \count(self::initializeOptions($log));

                    // Install again over the running server: same path, same
                    // argv, nothing about the resolved map moves.
                    Services::resolve($ide, CommandRegistry::class)->run('symfony-lsp:install');
                },
                1400 => function ($tui, $terminal) use ($log, &$launches): void {
                    $this->awaitPainted($tui, $terminal, 'symfony-lsp installed:');
                    $this->await(
                        static fn (): bool => \count(self::initializeOptions($log)) > $launches,
                        what: 'the upgraded binary to be launched, not the unlinked one still running',
                    );
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testInstallCommandDownloadsVerifiesAndRelaunchesTheServer(): void
    {
        $home = new TempProject();
        \putenv('PORTA_HOME='.$home->dir);
        $project = new TempProject([
            't.twig' => "x\n",
            'stubs/.keep' => '',
            '.porta.json' => \json_encode(['enabledPlugins' => ['symfony-lsp']]),
        ]);
        self::stubDownloadTools($project);
        $installed = $home->dir.'/.porta/bin/symfony-lsp';

        $this->runIde(
            $project,
            $project->path('t.twig'),
            [
                300 => function ($tui, $terminal, $ide): void {
                    $this->awaitPainted($tui, $terminal, 'LSP:no server');
                    $registry = Services::resolve($ide, CommandRegistry::class);
                    $registry->run('symfony-lsp:install');
                    // A second invocation while the download runs hits the
                    // single-flight guard instead of racing a second chain.
                    $registry->run('symfony-lsp:install');
                    $this->awaitPainted($tui, $terminal, 'symfony-lsp: already running');
                },
                600 => function ($tui, $terminal) use ($installed, $project): void {
                    // Terminal-state, not success-only: a broken chain fires
                    // the failure toast, and this must fail fast on it rather
                    // than burn the budget as a bare timeout.
                    $this->await(
                        static fn (): bool => \is_file($installed) || \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'install failed'),
                        what: 'the install to land the binary or report why not',
                    );
                    $this->assertFileExists($installed, 'the install chain reported a failure instead of landing the binary');
                    $this->assertTrue(\is_executable($installed), 'the landed binary is runnable');
                    $this->awaitPainted($tui, $terminal, 'symfony-lsp installed:');

                    // Installing writes NOTHING to the project's config. It
                    // used to write binary:'' purely to trigger a reconfigure,
                    // putting a key for a machine-global install into a file
                    // that travels with the project.
                    /** @var array{plugins?: array<string, array<string, mixed>>} $config */
                    $config = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true);
                    $this->assertArrayNotHasKey('binary', $config['plugins']['symfony-lsp'] ?? [], 'a machine-global install leaves the shared config alone');
                },
                900 => function ($tui, $terminal): void {
                    // The explicit relaunch took effect live, and with the
                    // setting empty and no PATH copy this can ONLY resolve
                    // through the ~/.porta/bin fallback — the discovery step a
                    // mutation of ServerConfig::installed() would kill.
                    $this->awaitPainted($tui, $terminal, 'LSP:symfony-lsp');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testInstallRefusesWhenThereIsNoHomeToInstallInto(): void
    {
        // No PORTA_HOME (tearDown restores it) and no HOME: there is no
        // user-owned landing spot, and the shared /tmp is deliberately not
        // one — the command must refuse, not degrade.
        $savedHome = \getenv('HOME');
        \putenv('PORTA_HOME');
        \putenv('HOME');
        try {
            $project = new TempProject([
                'a.php' => "<?php\n",
                '.porta.json' => \json_encode(['enabledPlugins' => ['symfony-lsp']]),
            ]);

            $this->runIde(
                $project,
                $project->path('a.php'),
                [
                    300 => function ($tui, $terminal, $ide): void {
                        Services::resolve($ide, CommandRegistry::class)->run('symfony-lsp:install');
                        // The toast box truncates long messages; await the
                        // head of the cause, not the full sentence.
                        $this->awaitPainted($tui, $terminal, 'install failed: no home');
                    },
                ],
            );
        } finally {
            \putenv('HOME'.(false === $savedHome ? '' : '='.$savedHome));
        }

        $this->addToAssertionCount(1);
    }

    public function testAFailedInstallReportsWhyInsteadOfHanging(): void
    {
        // PATH is /nonexistent (setUp): the very first curl cannot spawn, and
        // the failure must surface as a toast — not wedge the busy flag.
        $project = new TempProject([
            'a.php' => "<?php\n",
            '.porta.json' => \json_encode(['enabledPlugins' => ['symfony-lsp']]),
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                300 => function ($tui, $terminal, $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('symfony-lsp:install');
                    // With no curl to spawn, the cause is a missing curl —
                    // not a network fault, and not the old catch-all that
                    // stood for every possible failure at once.
                    //
                    // Awaited as a fragment that survives the toast's WRAP:
                    // the card reflows onto up to MAX_MESSAGE_ROWS rows, so a
                    // phrase spanning a row boundary never matches contiguously
                    // however long the test waits.
                    $this->awaitPainted($tui, $terminal, 'install failed: curl');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testTheLanguagesSettingNarrowsTheClaimLive(): void
    {
        $project = self::project('lsp.log', settings: ['languages' => 'php'], files: ['t.twig' => "x\n"]);

        $this->runIde(
            $project,
            $project->path('t.twig'),
            [
                300 => function ($tui, $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'LSP:no server');
                    $this->assertStringContainsString('LSP:no server', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'twig is out of the claim, so nothing serves it');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testWithoutABinaryEveryClaimResolvesToNothing(): void
    {
        // Enabled, but no binary configured and PATH is empty: the resolvers
        // return null for every language, so php keeps its settings hint.
        $project = new TempProject([
            'a.php' => "<?php\n",
            '.porta.json' => \json_encode(['enabledPlugins' => ['symfony-lsp']]),
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                300 => function ($tui, $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'LSP:none (enable a PHP server in Settings)');
                    $this->assertStringContainsString('LSP:none (enable a PHP server in Settings)', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
