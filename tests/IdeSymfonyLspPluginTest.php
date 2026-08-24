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

    protected function setUp(): void
    {
        putenv('PATH=/nonexistent');
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
        file_put_contents($binary, $stub);
        chmod($binary, permissions: 0o755);
        file_put_contents(
            $project->path('.porta.json'),
            json_encode([
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
        if (!is_file($log)) {
            return [];
        }
        $options = [];
        foreach (array_filter(explode("\n", (string) file_get_contents($log))) as $line) {
            /** @var array{method?: string, params?: array{initializationOptions?: array<string, mixed>}}|null $message */
            $message = json_decode($line, associative: true);
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
                    $this->await(static fn (): bool => [] !== self::initializeOptions($log));
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
                    $this->await(static fn (): bool => \count(self::initializeOptions($log)) >= 2);
                    $options = self::initializeOptions($log)[1];
                    $this->assertSame(true, $options['runtimeIndexing'] ?? null, 'the toggled setting reached the relaunch');
                    $this->assertSame(true, $options['workspaceTrust'] ?? null, 'runtime indexing grants trust');
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
            '.porta.json' => json_encode(['enabledPlugins' => ['symfony-lsp']]),
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
