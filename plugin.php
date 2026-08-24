<?php

declare(strict_types=1);

use Porta\Overlay\State\ToastSeverity;
use Porta\Plugin\Api\AbstractToolPlugin;
use Porta\Plugin\Api\BinarySearch;
use Porta\Plugin\Api\PluginApi;
use Porta\SymfonyLsp\Installer;
use Porta\SymfonyLsp\Release;
use Porta\SymfonyLsp\ServerConfig;

/**
 * The official Symfony language server (github.com/symfony/language-tools) —
 * Symfony-aware completion, navigation, references, diagnostics and code
 * lenses for routing, DI, Twig, translations, env vars, Doctrine and more. It
 * ships ONLY as a self-contained `symfony-lsp` binary on GitHub Releases —
 * not on Packagist (and its entry script cannot run installed as a composer
 * dependency), not in brew or cargo — so instead of an install offer the
 * plugin carries its own: "Symfony LSP: Install Server" resolves the latest
 * release, downloads the platform archive, verifies it against SHA256SUMS and
 * lands the binary at `~/.porta/bin/symfony-lsp` (PORTA_HOME-aware, shared by
 * every project). Discovery: the `binary` setting, then the PATH
 * ({@see BinarySearch::PathOnly}), then that installed copy.
 *
 * It serves php, twig, yaml, xml, json and dotenv (narrow the claim with the
 * `languages` setting). It is built to run BESIDE a general PHP server and
 * Porta routes one server per language, so its php claim only wins when
 * neither phpactor nor phpantom is enabled — twig/yaml/xml/json/dotenv are
 * its own either way. javascript/typescript are deliberately not claimed:
 * that would displace the auto-detected typescript-language-server with a
 * server that is not a general TS one.
 *
 * The runtime-indexing setting is the workspace-trust decision: on, the
 * server executes the application's PHP to index the real container and
 * routes; off (the default), it stays in static-only mode. It travels as
 * `initializationOptions`, re-read on every LSP (re)configure, so a settings
 * edit relaunches the server with the new values.
 */
return new class('symfony-lsp', 'symfony-lsp', BinarySearch::PathOnly) extends AbstractToolPlugin {
    private bool $installing = false;

    public function activate(PluginApi $api): void
    {
        $portaHome = getenv('PORTA_HOME');
        $home = \is_string($portaHome) && '' !== $portaHome ? $portaHome : getenv('HOME');
        $binDir = (\is_string($home) && '' !== $home ? $home : sys_get_temp_dir()).'/.porta/bin';

        $api->registerSetting('binary', 'symfony-lsp binary', 'text', '', hint: 'empty = PATH, then ~/.porta/bin (run "Symfony LSP: Install Server" to download)');
        $api->registerSetting('languages', 'Languages', 'text', ServerConfig::DEFAULT_LANGUAGES, hint: 'comma-separated; * = every language');
        $api->registerSetting('runtime-indexing', 'Runtime indexing (trusts the workspace: the server executes its PHP)', 'bool', false);
        $api->registerSetting('php-command', 'PHP command', 'text', 'php', hint: 'inspects the application, e.g. "symfony php"');
        $api->registerSetting('environment', 'Symfony environment', 'text', 'dev');

        $config = new ServerConfig();
        $options = static fn (): array => $config->initializationOptions(
            (string) $api->setting('php-command'),
            (string) $api->setting('environment'),
            (bool) $api->setting('runtime-indexing'),
        );
        foreach (ServerConfig::LANGUAGES as $language) {
            $api->registerLanguageServer(
                $language,
                'symfony-lsp',
                fn (): ?array => $config->command($this->binary($api) ?? $config->installed($binDir), $this->claimedLanguages($api), $language),
                $options,
            );
        }

        $api->registerCommand('install', 'Symfony LSP: Install Server', function () use ($api, $binDir): void {
            if ($this->installing) {
                $api->flash($api->trans('%tool%: already running', ['%tool%' => $this->name()]));

                return;
            }
            $this->installing = true;
            $api->flash($api->trans('symfony-lsp: downloading the latest release…'), 4.0);
            $installer = new Installer(new Release(), $binDir, sys_get_temp_dir().'/porta-symfony-lsp-'.(int) getmypid());
            $installer->install(
                \PHP_OS_FAMILY,
                php_uname('m'),
                function (string $path) use ($api): void {
                    $this->installing = false;
                    // Persisting the path is also what relaunches the LSP live.
                    $api->changeSetting('binary', $path);
                    $api->notify($api->trans('symfony-lsp installed: %path%', ['%path%' => $path]), ToastSeverity::Success, 4.0);
                },
                function (string $why) use ($api): void {
                    $this->installing = false;
                    $api->notify($api->trans('symfony-lsp install failed: %why%', ['%why%' => $why]), ToastSeverity::Error, 4.0);
                },
            );
        });
        $api->addMenuItem('install');
    }
};
