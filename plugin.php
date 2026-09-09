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
 * neither phpactor nor phpantom is enabled — by LOAD order, so enabling a
 * PHP server live after this plugin takes the php claim back at the next
 * launch — and twig/yaml/xml/json/dotenv are its own either way.
 * javascript/typescript are deliberately not claimed:
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
        $config = new ServerConfig();
        $binDir = $config->binDir(getenv('PORTA_HOME'), getenv('HOME'));

        $api->registerSetting('binary', 'symfony-lsp binary', 'text', '', hint: 'empty = PATH, then ~/.porta/bin (run "Symfony LSP: Install Server" to download)');
        $api->registerSetting('languages', 'Languages', 'text', ServerConfig::DEFAULT_LANGUAGES, hint: 'comma-separated; * = every language');
        $api->registerSetting('runtime-indexing', 'Runtime indexing (trusts the workspace: the server executes its PHP)', 'bool', false);
        $api->registerSetting('php-command', 'PHP command', 'text', 'php', hint: 'inspects the application, e.g. "symfony php"');
        $api->registerSetting('environment', 'Symfony environment', 'text', 'dev');

        $options = static fn (): array => $config->initializationOptions(
            (string) $api->setting('php-command'),
            (string) $api->setting('environment'),
            (bool) $api->setting('runtime-indexing'),
        );
        foreach (ServerConfig::LANGUAGES as $language) {
            $api->registerLanguageServer(
                $language,
                'symfony-lsp',
                fn (): ?array => $config->command($config->binary($this->binary($api), $binDir), $this->claimedLanguages($api), $language),
                $options,
            );
        }

        $api->registerCommand('install', 'Symfony LSP: Install Server', function () use ($api, $binDir): void {
            if (null === $binDir) {
                $api->notify($api->trans('symfony-lsp install failed: %why%', ['%why%' => 'no home directory to install into']), ToastSeverity::Error, 4.0);

                return;
            }
            if ($this->installing) {
                $api->flash($api->trans('%tool%: already running', ['%tool%' => $this->name()]));

                return;
            }
            $this->installing = true;
            $api->flash($api->trans('symfony-lsp: downloading the latest release…'), 4.0);
            // Scratch under the user-owned ~/.porta, not the shared /tmp: a
            // predictable world-writable path invites a symlink swap between
            // the checksum-verified extract and the copy into bin.
            // A token only matters for the API fallback (60/hour per IP without
            // one); the redirect the resolver tries first needs none. Read
            // here rather than in Installer so the plugin owns every getenv().
            $token = getenv('GITHUB_TOKEN');
            if (false === $token || '' === $token) {
                $token = getenv('GH_TOKEN');
            }
            $token = false === $token ? '' : $token;
            $installer = new Installer(new Release(), $binDir, \dirname($binDir).'/tmp/symfony-lsp-install-'.(int) getmypid(), token: $token);
            $installer->install(
                \PHP_OS_FAMILY,
                php_uname('m'),
                function (string $path) use ($api): void {
                    $this->installing = false;
                    // Clear the setting rather than persisting $path: the
                    // .porta.json this writes travels with the project, and a
                    // machine-specific absolute path would shadow teammates'
                    // own copies. The write (even of the same value) is also
                    // what relaunches the LSP live — resolved through the
                    // ~/.porta/bin fallback from here on.
                    $api->changeSetting('binary', '');
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
