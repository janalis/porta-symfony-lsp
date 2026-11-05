<?php

declare(strict_types=1);

use Porta\Plugin\Api\AbstractPlugin;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Api\ServerNotFound;
use Porta\Plugin\Api\ServerRole;
use Porta\SymfonyLsp\ServerConfig;

/**
 * The official Symfony language server (github.com/symfony/language-tools) —
 * Symfony-aware completion, navigation, references, diagnostics and code
 * lenses for routing, DI, Twig, translations, env vars, Doctrine and more. It
 * ships ONLY as a self-contained `symfony-lsp` binary on GitHub Releases —
 * not on Packagist (its entry script cannot run installed as a composer
 * dependency), not in brew or cargo — so its manifest declares that release
 * (`extra.porta.tools`), and core installs and updates it from the plugin's
 * Settings page: the latest release's platform archive, verified against
 * SHA256SUMS, landed at `~/.porta/bin/symfony-lsp` (PORTA_HOME-aware, shared
 * by every project). Discovery is core's too: the `binary` setting, then the
 * PATH, then that installed copy.
 *
 * It serves php, twig, yaml, xml, json and dotenv (narrow the claim with the
 * `languages` setting). twig/yaml/xml/json/dotenv are its own; php is claimed
 * as {@see ServerRole::Supplementary}, because this server is built to run
 * BESIDE a general PHP one — it runs alongside phpactor or phpantom rather
 * than instead of either, and is promoted to the php server only when neither
 * is enabled.
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
return new class('symfony-lsp') extends AbstractPlugin {
    public function activate(PluginApi $api): void
    {
        $config = new ServerConfig();

        $api->registerSetting('binary', 'symfony-lsp binary', 'text', '', hint: 'empty = PATH, then ~/.porta/bin (install it under Tools below)');
        $api->registerSetting('languages', 'Languages', 'text', ServerConfig::DEFAULT_LANGUAGES, hint: 'comma-separated; * = every language');
        $api->registerSetting('runtime-indexing', 'Runtime indexing (trusts the workspace: the server executes its PHP)', 'bool', false);
        $api->registerSetting('php-command', 'PHP command', 'text', 'php', hint: 'inspects the application, e.g. "symfony php"');
        $api->registerSetting('environment', 'Symfony environment', 'text', 'dev');

        $options = static fn (): array => $config->initializationOptions(
            (string) $api->setting('php-command'),
            (string) $api->setting('environment'),
            // Even switched on globally, never for an untrusted project. Core
            // starts no server there at all (ServerLocator::restricted()), so
            // this is the second lock: should one ever start, it would still
            // not execute the project's PHP.
            (bool) $api->setting('runtime-indexing') && $api->projectTrusted(),
        );
        foreach (ServerConfig::LANGUAGES as $language) {
            $api->registerLanguageServer(
                $language,
                'symfony-lsp',
                fn (): array|ServerNotFound|null => $config->command($api->toolBinary('symfony-lsp'), $this->claimedLanguages($api), $language),
                $options,
                // php is SUPPLEMENTARY: this server is built to run beside a
                // general PHP one, and what it adds there — route names,
                // service ids, translation ids, Twig paths — is not a second
                // opinion on where a method is defined. It is still promoted
                // to the php server when no other plugin claims the language.
                'php' === $language ? ServerRole::Supplementary : ServerRole::Primary,
            );
        }
    }
};
