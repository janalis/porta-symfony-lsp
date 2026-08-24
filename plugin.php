<?php

declare(strict_types=1);

use Porta\Plugin\Api\AbstractToolPlugin;
use Porta\Plugin\Api\BinarySearch;
use Porta\Plugin\Api\PluginApi;
use Porta\SymfonyLsp\ServerConfig;

/**
 * The official Symfony language server (github.com/symfony/language-tools) —
 * Symfony-aware completion, navigation, references, diagnostics and code
 * lenses for routing, DI, Twig, translations, env vars, Doctrine and more. It
 * ships as a self-contained `symfony-lsp` binary on GitHub Releases (no
 * composer/brew/cargo source, so there is no install offer — the binary
 * setting's hint points at the download), discovered via the `binary` setting
 * then the PATH ({@see BinarySearch::PathOnly}).
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
    public function activate(PluginApi $api): void
    {
        $api->registerSetting('binary', 'symfony-lsp binary', 'text', '', hint: 'empty = symfony-lsp on PATH (download: github.com/symfony/language-tools releases)');
        $api->registerSetting('languages', 'Languages', 'text', ServerConfig::DEFAULT_LANGUAGES, hint: 'comma-separated; * = every language');
        $api->registerSetting('runtime-indexing', 'Runtime indexing (trusts the workspace: the server executes its PHP)', 'bool', false);
        $api->registerSetting('php-command', 'PHP command', 'text', 'php', hint: 'inspects the application, e.g. "symfony php"');
        $api->registerSetting('environment', 'Symfony environment', 'text', 'dev');

        $config = new ServerConfig();
        $options = fn (): array => $config->initializationOptions(
            (string) $api->setting('php-command'),
            (string) $api->setting('environment'),
            (bool) $api->setting('runtime-indexing'),
        );
        foreach (ServerConfig::LANGUAGES as $language) {
            $api->registerLanguageServer(
                $language,
                'symfony-lsp',
                fn (): ?array => $config->command($this->binary($api), $this->claimedLanguages($api), $language),
                $options,
            );
        }
    }
};
