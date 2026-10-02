<?php

declare(strict_types=1);

namespace Porta\SymfonyLsp\Tests;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Ide;
use Porta\Plugin\Runtime\PluginHost;
use Porta\SymfonyLsp\InstalledVersion;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\NavigatesSettings;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UntrustsProjects;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Workbench\Menu\MenuItem;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

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
    use UntrustsProjects;
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
        \putenv('FAKE_VERSION');
        \putenv('FAKE_DOWNLOAD_GATE');
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
        // It answers `--version` like the real server: the plugin asks the
        // configured binary what it is, and a stub that ignored the question
        // would sit on stdin instead of replying.
        $stub = "#!/bin/sh\nif [ \"$1\" = --version ]; then echo 'Symfony Language Tools 1.2.3'; exit 0; fi\nexec \"".\PHP_BINARY."\" \"{$server}\" \"{$project->path($logName)}\"\n";
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

    /**
     * Runtime indexing executes the project's PHP. Switched on globally — the
     * user's own choice — it still stays off in a project not trusted to run
     * code: only the project's own answer may let it.
     */
    public function testRuntimeIndexingStaysOffInAnUntrustedProject(): void
    {
        $project = self::project('lsp.log', files: ['t.twig' => "{{ app.user }}\n"]);
        $global = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true);
        $global['plugins']['symfony-lsp']['runtime-indexing'] = true;
        $this->untrustProjects();
        \mkdir($this->untrustingHome->path('.porta'));
        \file_put_contents($this->untrustingHome->path('.porta/config.json'), \json_encode($global));
        \unlink($project->path('.porta.json'));
        $log = $project->path('lsp.log');

        $this->runIde($project, $project->path('t.twig'), [
            300 => function () use ($log): void {
                $this->await(static fn (): bool => [] !== self::initializeOptions($log), what: 'the first initialize to reach the wire log');
                $this->assertFalse(self::initializeOptions($log)[0]['runtimeIndexing'] ?? null);
                $this->assertFalse(self::initializeOptions($log)[0]['workspaceTrust'] ?? null);
            },
        ]);
    }

    public function testServesTwigWithTheConfiguredOptionsAndTogglingRuntimeIndexingRelaunches(): void
    {
        $project = self::project('lsp.log', files: ['t.twig' => "{{ app.user }}\n"]);
        $log = $project->path('lsp.log');

        $this->runIde(
            $project,
            $project->path('t.twig'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal) use ($log): void {
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
                500 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $tui->handleInput("\t"); // form → nav
                    $this->selectSettingsSection($tui->getById('settings-nav'), 'plugin:symfony-lsp');
                    $this->awaitPainted($tui, $terminal, 'symfony-lsp binary');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('symfony-lsp binary', $screen);
                    $this->assertStringContainsString('Runtime indexing', $screen);
                    // The server is a GitHub release, not a package, so the
                    // offer is a SELF-install: the plugin's own command behind
                    // the button. That row does not disappear once the binary
                    // is there — the thing that downloads it is the thing that
                    // replaces it — so it reads "Update", with what is
                    // installed beside it.
                    $this->assertStringContainsString('Update symfony-lsp', $screen);
                    $this->assertStringContainsString('1.2.3', $screen, 'the row says what is installed');
                    $this->assertStringNotContainsString('Install symfony-lsp', $screen, 'not a package-manager offer');

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
                    $this->assertTrue($options['runtimeIndexing'] ?? null, 'the toggled setting reached the relaunch');
                    $this->assertTrue($options['workspaceTrust'] ?? null, 'runtime indexing grants trust');
                },
            ],
            // Tall enough to show the whole plugin page: its checkboxes are boxes,
            // and at 24 rows its button row sits below the window.
            rows: 40,
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
            $version = getenv('FAKE_VERSION');
            $version = false === $version || '' === $version ? '9.9.9' : $version;
            if (null !== $out) {
                // `--progress-bar --stderr -` puts curl's meter on stdout,
                // which is what the status cell's percentage is read from.
                if (in_array('--progress-bar', $args, true)) {
                    fwrite(STDOUT, "\r#####   20.0%\r##########   41.7%");
                    fflush(STDOUT);
                    // Held mid-download until the test has seen the meter:
                    // the cell only shows a percentage between this chunk and
                    // curl's exit, and nothing else bounds that gap from below.
                    $gate = getenv('FAKE_DOWNLOAD_GATE');
                    for ($waited = 0; false !== $gate && '' !== $gate && !file_exists($gate) && $waited < 600; ++$waited) {
                        usleep(50_000);
                    }
                }
                copy((string) getenv('FAKE_TARBALL'), $out);
                exit(0);
            }
            if (str_contains($url, 'releases/latest')) {
                echo '{"tag_name":"v', $version, '"}';
                exit(0);
            }
            $hash = hash_file('sha256', (string) getenv('FAKE_TARBALL'));
            foreach (['macos-arm64', 'macos-x64', 'linux-arm64', 'linux-x64'] as $platform) {
                echo "{$hash}  symfony-lsp-v{$version}-{$platform}.tar.gz\n";
            }
            exit(0);
            PHP;
        $tar = <<<PHP
            // tar -xzf <tarball> -C <dir>
            \$dir = \$argv[4].'/'.basename(\$argv[2], '.tar.gz');
            \$version = getenv('FAKE_VERSION');
            \$version = false === \$version || '' === \$version ? '9.9.9' : \$version;
            mkdir(\$dir, 0o755, true);
            file_put_contents(\$dir.'/symfony-lsp', "#!/bin/sh\\nif [ \\"\\$1\\" = --version ]; then echo \"Symfony Language Tools \$version\"; exit 0; fi\\nexec \\"".PHP_BINARY."\\" \\"{$mock}\\"{$logArg}\\n");
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
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($installed): void {
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
     * The menu entries FLIP once the server lands.
     *
     * The one assertion the Update commit never made. It checked that both
     * command ids were registered, which they were — while the predicate
     * behind them came from AbstractToolPlugin::binaryAvailable(), i.e. the
     * `binary` setting then the PATH. The install lands in ~/.porta/bin, which
     * nothing puts on the PATH, so on a real machine "Install Server" stayed
     * enabled and "Update Server" stayed greyed for good: exactly the symptom
     * the Update entry was added to cure. Registration is not enablement, and
     * a 100% mutation score over the wrong assertion says nothing.
     */
    public function testInstallingFlipsTheMenuFromInstallToUpdate(): void
    {
        $home = new TempProject();
        \putenv('PORTA_HOME='.$home->dir);
        $project = new TempProject([
            't.twig' => "x\n",
            'stubs/.keep' => '',
            '.porta.json' => \json_encode(['enabledPlugins' => ['symfony-lsp']]),
        ]);
        self::stubDownloadTools($project);

        $this->runIde(
            $project,
            $project->path('t.twig'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->frame($tui);
                    [$install, $update] = $this->serverMenuItems($tui);
                    $this->assertTrue($install->enabled(), 'no binary yet → Install is the live entry');
                    $this->assertFalse($update->enabled(), 'nothing to update yet');

                    Services::resolve($ide, CommandRegistry::class)->run('symfony-lsp:install');
                    $this->awaitPainted($tui, $terminal, 'symfony-lsp installed:');

                    // The SAME MenuItem objects: their predicates are live, so
                    // re-reading them is the whole point. ~/.porta/bin is still
                    // not on the PATH — resolution has to reach past it.
                    $this->assertFalse($install->enabled(), 'installed → Install stops offering itself');
                    $this->assertTrue($update->enabled(), 'installed → Update is the live entry');
                },
            ],
        );
    }

    /**
     * The plugin's two server entries in the Plugins menu, in [install, update]
     * order.
     *
     * @return array{0: MenuItem, 1: MenuItem}
     */
    private function serverMenuItems(Tui $tui): array
    {
        $items = [];
        foreach (Probe::get($tui->getById('menu'), 'menus') as $menu) {
            foreach ($menu->items as $item) {
                if (!\str_starts_with($item->label, 'Symfony LSP: ')) {
                    continue;
                }

                $items[$item->label] = $item;
            }
        }
        $this->assertArrayHasKey('Symfony LSP: Install Server', $items);
        $this->assertArrayHasKey('Symfony LSP: Update Server', $items);

        return [$items['Symfony LSP: Install Server'], $items['Symfony LSP: Update Server']];
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
    public function testDisabledMidInstallTheDownloadStopsAndSaysNothing(): void
    {
        $home = new TempProject();
        \putenv('PORTA_HOME='.$home->dir);
        $project = new TempProject([
            't.twig' => "x\n",
            'stubs/.keep' => '',
            '.porta.json' => \json_encode(['enabledPlugins' => ['symfony-lsp']]),
        ]);
        self::stubDownloadTools($project, 'lsp.log');

        $this->runIde(
            $project,
            $project->path('t.twig'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitPainted($tui, $terminal, 'LSP:symfony-lsp not found');
                    $registry = Services::resolve($ide, CommandRegistry::class);
                    $registry->run('symfony-lsp:install');
                    $manager = Probe::object(Services::resolve($ide, PluginHost::class), 'manager');
                    $plugin = Probe::get($manager, 'instances')['symfony-lsp'];
                    \assert(\is_object($plugin), description: 'the activated instance');
                    $manager->deactivate('symfony-lsp', $registry);

                    $this->assertFalse(Probe::get($plugin, 'installing'), 'the slot is free');
                    $this->assertSame([], Probe::get(Probe::object($plugin, 'runs'), 'running'), 'and nothing it started runs on');
                },
                900 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->assertStringNotContainsString('symfony-lsp installed', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'no word from a plugin that is gone');
                },
            ],
        );

        $this->assertFileDoesNotExist($home->path('.porta/bin/symfony-lsp'));
    }

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
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitPainted($tui, $terminal, 'LSP:symfony-lsp not found');
                    Services::resolve($ide, CommandRegistry::class)->run('symfony-lsp:install');
                },
                800 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($log, &$launches): void {
                    $this->awaitPainted($tui, $terminal, 'symfony-lsp installed:');
                    $this->await(static fn (): bool => [] !== self::initializeOptions($log), what: 'the freshly installed server to launch');
                    $launches = \count(self::initializeOptions($log));

                    // Install again over the running server: same path, same
                    // argv, nothing about the resolved map moves.
                    Services::resolve($ide, CommandRegistry::class)->run('symfony-lsp:install');
                },
                1400 => function (Tui $tui, VirtualTerminal $terminal) use ($log, &$launches): void {
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
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitPainted($tui, $terminal, 'LSP:symfony-lsp not found');
                    $registry = Services::resolve($ide, CommandRegistry::class);
                    $registry->run('symfony-lsp:install');
                    // A second invocation while the download runs hits the
                    // single-flight guard instead of racing a second chain.
                    $registry->run('symfony-lsp:install');
                    $this->awaitPainted($tui, $terminal, 'symfony-lsp: already running');
                },
                600 => function (Tui $tui, VirtualTerminal $terminal) use ($installed, $project): void {
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
                900 => function (Tui $tui, VirtualTerminal $terminal): void {
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
                    300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                        $registry = Services::resolve($ide, CommandRegistry::class);
                        $registry->run('symfony-lsp:install');
                        // The toast box truncates long messages; await the
                        // head of the cause, not the full sentence.
                        $this->awaitPainted($tui, $terminal, 'install failed: no home');

                        // Update refuses for the same reason and in the same
                        // words: it is the same landing spot, and a version
                        // check that then cannot install anything would spend
                        // a request to say nothing.
                        $terminal->clearOutput();
                        $registry->run('symfony-lsp:update');
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
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
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

                    // Update fails on the same missing curl and says so under
                    // its OWN heading: "install failed" on a command the user
                    // ran as an update reads as though it ran the wrong verb.
                    $terminal->clearOutput();
                    Services::resolve($ide, CommandRegistry::class)->run('symfony-lsp:update');
                    $this->awaitPainted($tui, $terminal, 'update failed: curl');
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
                300 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'LSP:no server');
                    $this->assertStringContainsString('LSP:no server', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'twig is out of the claim, so nothing serves it');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testWithoutABinaryEveryClaimIsSaidToBeNotFound(): void
    {
        // Enabled, but no binary configured and PATH is empty: every language
        // it claims is unserved, and the status bar says why — not found.
        $project = new TempProject([
            'a.php' => "<?php\n",
            '.porta.json' => \json_encode(['enabledPlugins' => ['symfony-lsp']]),
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'LSP:symfony-lsp not found');
                    $this->assertStringContainsString('LSP:symfony-lsp not found', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    /**
     * Update compares before it downloads.
     *
     * "Update Server" was the install closure under another name: it fetched
     * ~9MB unconditionally, because nothing recorded what was already
     * installed. Now the tag lookup happens first — one redirect — and a
     * matching version ends the operation there.
     *
     * Proven by the INODE, not by the toast: `rename()` replaces the file, so
     * a download that happened anyway would change it whatever was printed.
     */
    public function testUpdateOnTheLatestVersionDownloadsNothing(): void
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
        $inode = 0;

        $this->runIde(
            $project,
            $project->path('t.twig'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($installed, &$inode): void {
                    Services::resolve($ide, CommandRegistry::class)->run('symfony-lsp:install');
                    $this->awaitPainted($tui, $terminal, 'symfony-lsp installed:');
                    $this->assertFileExists($installed);
                    $inode = \fileinode($installed);

                    $terminal->clearOutput();
                    Services::resolve($ide, CommandRegistry::class)->run('symfony-lsp:update');
                    // Awaited WITHOUT the version: the toast card wraps at
                    // ~40 columns and "…up to date (9.9.9)" straddles the
                    // break, which no amount of waiting can match.
                    $this->awaitPainted($tui, $terminal, 'already up to date');
                    $this->assertStringContainsString('(9.9.9)', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the version it is already on, on the wrapped line');
                    $this->assertSame($inode, \fileinode($installed), 'nothing was downloaded, so nothing was renamed over the binary');
                },
            ],
        );
    }

    /**
     * A NEWER release downloads, and the toast names the move.
     *
     * `installed: <path>` after an upgrade reads as though nothing had
     * changed, which is the complaint the Update entry was added for in the
     * first place — the entry existed, the report did not.
     */
    public function testUpdateToANewerReleaseNamesTheVersionItMovedFrom(): void
    {
        $home = new TempProject();
        \putenv('PORTA_HOME='.$home->dir);
        $project = new TempProject([
            't.twig' => "x\n",
            'stubs/.keep' => '',
            '.porta.json' => \json_encode(['enabledPlugins' => ['symfony-lsp']]),
        ]);
        self::stubDownloadTools($project);

        $this->runIde(
            $project,
            $project->path('t.twig'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('symfony-lsp:install');
                    $this->awaitPainted($tui, $terminal, 'symfony-lsp installed:');

                    // Publish a newer release under the same stubs.
                    \putenv('FAKE_VERSION=9.9.10');
                    $terminal->clearOutput();
                    Services::resolve($ide, CommandRegistry::class)->run('symfony-lsp:update');
                    $this->awaitPainted($tui, $terminal, 'symfony-lsp updated: 9.9.9');
                    $this->assertStringContainsString(
                        'symfony-lsp updated: 9.9.9',
                        AnsiUtils::stripAnsiCodes($terminal->getOutput()),
                        'the toast says what it moved from',
                    );
                },
            ],
        );
    }

    /**
     * The status cell carries the install and then gets out of the way.
     *
     * The whole chain — two curl calls, a sha256, a tar and a smoke test —
     * used to sit behind one four-second flash, after which the screen said
     * nothing at all until the operation ended. A status segment can stay for
     * as long as the work does; it must also LEAVE, or a finished install
     * parks "downloading 41%" in the bar for the rest of the session.
     */
    public function testTheStatusCellIsEmptyBeforeAnInstallAndEmptyAgainAfterIt(): void
    {
        $home = new TempProject();
        \putenv('PORTA_HOME='.$home->dir);
        $project = new TempProject([
            't.twig' => "x\n",
            'stubs/.keep' => '',
            '.porta.json' => \json_encode(['enabledPlugins' => ['symfony-lsp']]),
        ]);
        self::stubDownloadTools($project);
        // The meter is transient: the cell shows it from the chunk that names
        // it until curl exits. A busy loop can read that chunk and the exit in
        // back-to-back turns, with no frame between — the percentage is then
        // never painted and the await below burns its whole budget. So the
        // stub holds the download open until the test has seen it.
        $gate = $project->path('download.gate');
        \putenv('FAKE_DOWNLOAD_GATE='.$gate);

        $this->runIde(
            $project,
            $project->path('t.twig'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($gate): void {
                    // getOutput() accumulates the whole session, so a NEGATIVE
                    // screen assertion has to start from a cleared terminal
                    // and force a frame of its own.
                    $terminal->clearOutput();
                    $this->frame($tui);
                    $this->assertStringNotContainsString('symfony-lsp:', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'nothing is installing, so the cell shows nothing');

                    Services::resolve($ide, CommandRegistry::class)->run('symfony-lsp:install');
                    // The percentage curl's meter names, rendered into the
                    // cell — the one thing the old single flash could never
                    // say. Awaited, not merely asserted after the fact: it is
                    // transient by nature.
                    $this->awaitPainted($tui, $terminal, 'symfony-lsp: downloading 41%');
                    \touch($gate); // seen: let the download finish
                    $this->awaitPainted($tui, $terminal, 'symfony-lsp installed:');

                    $terminal->clearOutput();
                    $this->frame($tui);
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    // "downloading" alone would match the opening TOAST, which
                    // is still on screen for its four seconds. The percentage
                    // and the smoke-test wording are the cell's alone.
                    $this->assertStringNotContainsString('downloading 41%', $screen, 'the finished install let go of the cell');
                    $this->assertStringNotContainsString('checking the binary', $screen);
                },
            ],
        );
    }

    /**
     * Plant an already-installed server under $home, answering `--version`
     * with $version and otherwise behaving like the mock LSP server.
     *
     * The download stubs land one of these; this is the same thing without a
     * download, which is what "a machine that installed yesterday" looks like.
     */
    private static function plantInstalledServer(TempProject $home, string $version): string
    {
        $mock = \dirname(__DIR__, levels: 3).'/tests/Support/mock-lsp-server.php';
        $binDir = $home->dir.'/.porta/bin';
        \mkdir($binDir, permissions: 0o755, recursive: true);
        $path = $binDir.'/symfony-lsp';
        \file_put_contents($path, "#!/bin/sh\nif [ \"$1\" = --version ]; then echo 'Symfony Language Tools {$version}'; exit 0; fi\nexec \"".\PHP_BINARY."\" \"{$mock}\"\n");
        \chmod($path, permissions: 0o755);

        return $path;
    }

    /**
     * A newer release announces itself, once a day, without being asked.
     *
     * Nothing used to look: you found out a release existed by running Update
     * on the off-chance, which downloaded ~9MB whatever the answer was.
     */
    public function testANewerReleaseIsOfferedOnceADay(): void
    {
        $home = new TempProject();
        \putenv('PORTA_HOME='.$home->dir);
        self::plantInstalledServer($home, '9.9.9');
        $project = new TempProject([
            't.twig' => "x\n",
            'stubs/.keep' => '',
            '.porta.json' => \json_encode(['enabledPlugins' => ['symfony-lsp']]),
        ]);
        self::stubDownloadTools($project);
        \putenv('FAKE_VERSION=9.9.10');
        $note = $home->dir.'/.porta/symfony-lsp-check.json';

        $this->runIde(
            $project,
            $project->path('t.twig'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal) use ($note): void {
                    // Awaited as a fragment that survives the toast's wrap.
                    $this->awaitPainted($tui, $terminal, 'symfony-lsp 9.9.10 is available');
                    $this->assertFileExists($note, 'the machine records that it LOOKED, so it does not look again today');
                    $state = \json_decode((string) \file_get_contents($note), associative: true);
                    $this->assertSame('v9.9.10', $state['latestTag'] ?? null);
                },
            ],
        );
    }

    /**
     * Already on the latest, or told not to look: nothing is said.
     *
     * A daily toast that reports "you are up to date" is a daily toast nobody
     * asked for.
     */
    public function testNothingIsSaidWhenTheInstalledServerIsCurrent(): void
    {
        $home = new TempProject();
        \putenv('PORTA_HOME='.$home->dir);
        self::plantInstalledServer($home, '9.9.9');
        $project = new TempProject([
            't.twig' => "x\n",
            'stubs/.keep' => '',
            '.porta.json' => \json_encode(['enabledPlugins' => ['symfony-lsp']]),
        ]);
        self::stubDownloadTools($project);
        $note = $home->dir.'/.porta/symfony-lsp-check.json';

        $this->runIde(
            $project,
            $project->path('t.twig'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal) use ($note): void {
                    // The note is the barrier: it is written by the same
                    // callback that would have toasted, so once it exists the
                    // check has finished and the silence is a decision.
                    $this->await(static fn (): bool => \is_file($note), what: 'the daily check to finish');
                    $terminal->clearOutput();
                    $this->frame($tui);
                    $this->assertStringNotContainsString('is available', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );
    }

    /** The setting is a real off switch: no note is written, because nothing looked. */
    public function testTheDailyCheckCanBeTurnedOff(): void
    {
        $home = new TempProject();
        \putenv('PORTA_HOME='.$home->dir);
        self::plantInstalledServer($home, '9.9.9');
        $project = new TempProject([
            't.twig' => "x\n",
            'stubs/.keep' => '',
            '.porta.json' => \json_encode([
                'enabledPlugins' => ['symfony-lsp'],
                'plugins' => ['symfony-lsp' => ['check-updates' => false]],
            ]),
        ]);
        self::stubDownloadTools($project);
        \putenv('FAKE_VERSION=9.9.10');

        $this->runIde(
            $project,
            $project->path('t.twig'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($home): void {
                    // The probe answering is what proves the check has already
                    // had its turn and declined — a barrier on the LSP session
                    // would let this assert nothing while the check was still
                    // ahead of it.
                    $this->await(
                        static fn (): bool => '' !== self::cachedVersion($ide),
                        what: 'the boot version probe to answer, after which the daily check has already decided',
                    );
                    $this->frame($tui);
                    $this->assertFileDoesNotExist($home->dir.'/.porta/symfony-lsp-check.json', 'nothing looked, so nothing was recorded');
                    $this->assertStringNotContainsString('is available', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );
    }

    /**
     * Once a day means once a day: a window opened an hour later does not ask
     * GitHub again.
     *
     * A per-launch check would spend several requests a day to learn the same
     * thing, and on a heavy day it is the very rate limit the install already
     * ran into.
     */
    public function testAMachineThatLookedTodayDoesNotLookAgain(): void
    {
        $home = new TempProject();
        \putenv('PORTA_HOME='.$home->dir);
        self::plantInstalledServer($home, '9.9.9');
        $note = $home->dir.'/.porta/symfony-lsp-check.json';
        \file_put_contents($note, (string) \json_encode(['checkedAt' => \time(), 'latestTag' => 'v9.9.9']));
        $project = new TempProject([
            't.twig' => "x\n",
            'stubs/.keep' => '',
            '.porta.json' => \json_encode(['enabledPlugins' => ['symfony-lsp']]),
        ]);
        self::stubDownloadTools($project);
        // A newer release IS out there; the point is that nothing looks.
        \putenv('FAKE_VERSION=9.9.10');

        $this->runIde(
            $project,
            $project->path('t.twig'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($note): void {
                    // The barrier has to cover the DECISION, and a decision
                    // not to act leaves nothing on screen and nothing on disk.
                    // The check runs inside the version probe's callback, so
                    // the probe having answered is proof the check has already
                    // had its turn — and that IS observable, through the
                    // plugin's own cache. Waiting on `LSP:` instead would pass
                    // while the check was still ahead of us.
                    $this->await(
                        static fn (): bool => '' !== self::cachedVersion($ide),
                        what: 'the boot version probe to answer, after which the daily check has already decided',
                    );

                    $state = \json_decode((string) \file_get_contents($note), associative: true);
                    $this->assertSame('v9.9.9', $state['latestTag'] ?? null, 'the note is untouched, so nothing asked');
                    $this->frame($tui);
                    $this->assertStringNotContainsString('is available', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );
    }

    /**
     * A check that cannot reach GitHub says nothing at all.
     *
     * The user asked for nothing here — a background check that toasts
     * "cannot reach github.com" at every launch on a plane is worse than no
     * check. It also records nothing, so the next launch tries again rather
     * than treating a failure as a day's answer.
     */
    public function testAFailedDailyCheckIsSilentAndRecordsNothing(): void
    {
        $home = new TempProject();
        \putenv('PORTA_HOME='.$home->dir);
        self::plantInstalledServer($home, '9.9.9');
        // PATH is /nonexistent (setUp) and no stubs are planted, so the tag
        // lookup cannot spawn curl at all.
        $project = new TempProject([
            't.twig' => "x\n",
            '.porta.json' => \json_encode(['enabledPlugins' => ['symfony-lsp']]),
        ]);

        $this->runIde(
            $project,
            $project->path('t.twig'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($home): void {
                    $this->await(
                        static fn (): bool => '' !== self::cachedVersion($ide),
                        what: 'the boot version probe to answer, after which the tag lookup has already failed',
                    );
                    $this->frame($tui);
                    $this->assertFileDoesNotExist($home->dir.'/.porta/symfony-lsp-check.json', 'a failure is not a day\'s answer');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringNotContainsString('is available', $screen);
                    $this->assertStringNotContainsString('failed', $screen, 'nobody asked for this check, so it does not complain');
                },
            ],
        );
    }

    /**
     * The plugin's settings page installs the server, through the plugin's own
     * command.
     *
     * The install offer machinery only knew composer, brew and cargo, so the
     * one plugin whose tool has no package at all was the one plugin whose
     * settings page could not offer a button. It runs `symfony-lsp:install`,
     * not a package manager, and the row stays afterwards as "Update" — a row
     * that vanished on success would take the update affordance with it.
     */
    public function testTheSettingsPageButtonInstallsTheServerAndThenOffersAnUpdate(): void
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
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($installed): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $tui->handleInput("\t"); // form → nav
                    $this->selectSettingsSection($tui->getById('settings-nav'), 'plugin:symfony-lsp');
                    $this->awaitPainted($tui, $terminal, 'Install symfony-lsp');
                    $this->assertFileDoesNotExist($installed);

                    // Down to the install row (the last but the plugin's
                    // Documentation) and press it.
                    $tui->handleInput("\t"); // nav → form
                    for ($i = 0; $i < 10; ++$i) {
                        $tui->handleInput("\x1b[B");
                    }
                    $tui->handleInput("\x1b[A");
                    $tui->handleInput("\r");

                    $this->awaitPainted($tui, $terminal, 'symfony-lsp installed:');
                    $this->assertFileExists($installed, 'the button ran the plugin\'s own installer');
                },
                900 => function (Tui $tui, VirtualTerminal $terminal): void {
                    // The rows re-derive, and the same row is now the UPDATE
                    // affordance, carrying what is installed.
                    $this->awaitPainted($tui, $terminal, 'Update symfony-lsp');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Update symfony-lsp', $screen);
                    $this->assertStringContainsString('9.9.9', $screen, 'the row says what is installed');
                },
            ],
            // Tall enough to show the whole plugin page: its checkboxes are boxes,
            // and at 24 rows its button row sits below the window.
            rows: 40,
        );
    }

    /**
     * What the running plugin believes is installed.
     *
     * Read through {@see Probe}, the blessed private-state seam: the version
     * is deliberately not on disk and deliberately not a setting, so there is
     * no other place to observe it — and it is the only barrier a test has for
     * "the boot probe has answered", which is what every assertion about the
     * daily check has to wait for.
     */
    private static function cachedVersion(Ide $ide): string
    {
        $plugin = Probe::get(Probe::object(Services::resolve($ide, PluginHost::class), 'manager'), 'instances')['symfony-lsp'];
        \assert(\is_object($plugin), description: 'the plugin manager keeps the activated instance by name');
        $versions = Probe::object($plugin, 'versions');
        Assert::assertInstanceOf(InstalledVersion::class, $versions, 'the plugin holds its version cache under $versions');

        return $versions->known();
    }
}
