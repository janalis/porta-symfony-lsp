<?php

declare(strict_types=1);

namespace Porta\SymfonyLsp\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\NavigatesSettings;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UntrustsProjects;
use Porta\Tests\Support\UsesBundledPlugins;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The symfony-lsp bundled plugin through a real IDE: it serves the languages
 * its `languages` setting claims, the launch carries the settings-derived
 * `initializationOptions` (workspaceTrust always explicit), the Settings page
 * installs the server from the release its manifest declares, and flipping
 * runtime-indexing relaunches the server live with trust granted.
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
            \json_encode(
                [
                    'enabledPlugins' => ['symfony-lsp'],
                    'plugins' => ['symfony-lsp' => ['binary' => $binary, ...$settings]],
                ],
                \JSON_THROW_ON_ERROR,
            ),
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
            $message = \json_decode($line, associative: true, flags: \JSON_THROW_ON_ERROR);
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
        $global = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $global['plugins']['symfony-lsp']['runtime-indexing'] = true;
        $this->untrustProjects();
        \mkdir($this->untrustingHome->path('.porta'));
        \file_put_contents($this->untrustingHome->path('.porta/config.json'), \json_encode($global, \JSON_THROW_ON_ERROR));
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
                    $this->selectSettingsSection($this->settingsNav($tui), 'plugin:symfony-lsp');
                    // The version is asked when the page first shows, and the
                    // page re-reads itself once it is in.
                    $this->awaitPainted($tui, $terminal, '┆ · 1.2.3 · ');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('symfony-lsp binary', $screen);
                    $this->assertStringContainsString('Runtime indexing', $screen);
                    // The binary is named by hand, so Porta does not manage
                    // it: the tool row says what is installed, and its Update
                    // is drawn disabled (dashed) rather than replacing a copy
                    // someone else put there.
                    $this->assertMatchesRegularExpression(
                        '/symfony-lsp — the Symfony language server\s+┆\s+Update\s+┆ · 1\.2\.3 · /u',
                        $screen,
                        'the row says what is installed, and offers no update',
                    );

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
            '.porta.json' => \json_encode(['enabledPlugins' => ['symfony-lsp']], \JSON_THROW_ON_ERROR),
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
     * The server is a GitHub release the manifest declares, so the plugin's
     * Tools row installs it — and, once it is there, the same row updates it:
     * a row that vanished on success would take the update affordance with it.
     */
    public function testTheSettingsPageButtonInstallsTheServerAndThenOffersAnUpdate(): void
    {
        $home = new TempProject();
        \putenv('PORTA_HOME='.$home->dir);
        $project = new TempProject([
            't.twig' => "x\n",
            'stubs/.keep' => '',
            '.porta.json' => \json_encode(['enabledPlugins' => ['symfony-lsp']], \JSON_THROW_ON_ERROR),
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
                    $this->selectSettingsSection($this->settingsNav($tui), 'plugin:symfony-lsp');
                    $this->awaitPainted($tui, $terminal, 'symfony-lsp — the Symfony language server');
                    $this->assertFileDoesNotExist($installed);

                    // Down to the tool's row (the last but the plugin's
                    // Documentation) and press it.
                    $tui->handleInput("\t"); // nav → form
                    for ($i = 0; $i < 10; ++$i) {
                        $tui->handleInput("\x1b[B");
                    }
                    $tui->handleInput("\x1b[A");
                    $tui->handleInput("\r");

                    $this->awaitPainted($tui, $terminal, 'Installed symfony-lsp 9.9.9');
                    $this->assertFileExists($installed, 'the button downloaded the release the manifest declares');
                },
                900 => function (Tui $tui, VirtualTerminal $terminal): void {
                    // The rows re-derive, and the same row is now the UPDATE
                    // affordance, carrying what is installed and where.
                    $this->awaitPainted($tui, $terminal, '9.9.9 · ~/.porta/bin/symfony-lsp');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Update', $screen);
                    $this->assertStringContainsString('9.9.9 · ~/.porta/bin/symfony-lsp', $screen, 'the row says what is installed, and where');
                },
            ],
            // Tall enough to show the whole plugin page: its checkboxes are boxes,
            // and at 24 rows its button row sits below the window. Wide enough
            // for the hint to show the whole path.
            columns: 220,
            rows: 40,
        );
    }
}
