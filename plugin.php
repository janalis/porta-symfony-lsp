<?php

declare(strict_types=1);

use Porta\Overlay\State\ToastSeverity;
use Porta\Plugin\Api\AbstractToolPlugin;
use Porta\Plugin\Api\BinarySearch;
use Porta\Plugin\Api\PluginApi;
use Porta\SymfonyLsp\InstalledVersion;
use Porta\SymfonyLsp\Installer;
use Porta\SymfonyLsp\InstallStep;
use Porta\SymfonyLsp\Release;
use Porta\SymfonyLsp\ServerConfig;
use Porta\SymfonyLsp\UpdateCheck;
use Symfony\Component\Filesystem\Filesystem;

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

    /** What the status cell shows while an install runs; '' the rest of the time. */
    private string $progress = '';

    /** Where the server is installed, or null when there is no home to install into. */
    private ?string $binDir = null;

    /**
     * ONE resolution, shared by the launcher and the menu.
     *
     * They used to disagree: the launcher asked ServerConfig (the `binary`
     * setting, then the PATH, then the installed copy in ~/.porta/bin), the
     * menu asked {@see AbstractToolPlugin::binaryAvailable()} — the setting,
     * then the PATH, full stop. Nothing puts ~/.porta/bin on the PATH, so
     * after a successful install "Install Server" stayed enabled and "Update
     * Server" stayed greyed, for good.
     *
     * @var \Closure(): ?string
     */
    private \Closure $resolve;

    private InstalledVersion $versions;

    public function activate(PluginApi $api): void
    {
        $config = new ServerConfig();
        $this->binDir = $config->binDir(getenv('PORTA_HOME'), getenv('HOME'));
        $this->resolve = fn (): ?string => $config->binary($this->binary($api), $this->binDir);
        $this->versions = new InstalledVersion(new Release());

        $api->registerSetting('binary', 'symfony-lsp binary', 'text', '', hint: 'empty = PATH, then ~/.porta/bin (run "Symfony LSP: Install Server" to download)');
        $api->registerSetting('languages', 'Languages', 'text', ServerConfig::DEFAULT_LANGUAGES, hint: 'comma-separated; * = every language');
        $api->registerSetting('runtime-indexing', 'Runtime indexing (trusts the workspace: the server executes its PHP)', 'bool', false);
        $api->registerSetting('php-command', 'PHP command', 'text', 'php', hint: 'inspects the application, e.g. "symfony php"');
        $api->registerSetting('environment', 'Symfony environment', 'text', 'dev');
        $api->registerSetting('check-updates', 'Check for a newer server once a day', 'bool', true);

        $options = static fn (): array => $config->initializationOptions(
            (string) $api->setting('php-command'),
            (string) $api->setting('environment'),
            (bool) $api->setting('runtime-indexing'),
        );
        foreach (ServerConfig::LANGUAGES as $language) {
            $api->registerLanguageServer(
                $language,
                'symfony-lsp',
                fn (): ?array => $config->command(($this->resolve)(), $this->claimedLanguages($api), $language),
                $options,
            );
        }

        // A ~9MB download behind two curl calls, a hash, a tar and a smoke
        // test used to be ONE four-second flash, after which the screen said
        // nothing for the rest of the operation. A status cell can stay for as
        // long as the work does, which a toast cannot.
        //
        // Pulled once per render, so it may only ever read a cached string —
        // and null, the normal state, shows nothing at all.
        $api->registerStatusSegment(fn (): ?string => '' === $this->progress ? null : $this->progress);

        // Two commands over one action, because the menu can only GREY an
        // entry, never hide it: an "Install Server" that stays put after
        // installing reads as though nothing happened, and there was no
        // update affordance at all — re-running install WAS the update, which
        // nobody could be expected to guess. Whichever one applies is the
        // enabled one.
        $installed = fn (): bool => null !== ($this->resolve)();
        $api->registerCommand('install', 'Symfony LSP: Install Server', function () use ($api): void {
            $this->install($api);
        });
        $api->registerCommand('update', 'Symfony LSP: Update Server', function () use ($api): void {
            $this->update($api);
        });
        $api->addMenuItem('install', static fn (): bool => !$installed());
        $api->addMenuItem('update', $installed);

        // The same two verbs on the plugin's own settings page, and the fact
        // the Welcome card needs to stop claiming it is done with no server
        // behind it. Registered AFTER the commands, because the self-install
        // candidate names one of them and the API checks that it exists.
        // registerBinarySetting() cannot do this for us: the `binary` setting
        // above has its own hint, and its resolution is ServerConfig's rather
        // than AbstractToolPlugin's.
        $api->registerInstaller(
            'symfony-lsp',
            $installed,
            [[PluginApi::INSTALL_SELF, 'install']],
            fn (): string => $this->versions->known(),
        );

        // Off the activate() phase (an action there throws) and off every
        // render path: one ~50ms subprocess, once, so known() can answer the
        // settings row and the update comparison without spawning. The daily
        // check rides the same hook, because it has nothing to compare against
        // until the probe has answered.
        $api->onReady(function () use ($api): void {
            $this->versions->probe(($this->resolve)(), function () use ($api): void {
                $this->checkForUpdates($api);
            });
        });
    }

    /**
     * Build an installer for this machine.
     *
     * The scratch directory is keyed on the pid so two windows cannot collide,
     * and named with the installer's own prefix so it can sweep what an
     * interrupted install (or a recycled pid) left behind. Under the
     * user-owned ~/.porta, not the shared /tmp: a predictable world-writable
     * path invites a symlink swap between the checksum-verified extract and
     * the copy into bin.
     */
    private function installer(PluginApi $api, string $binDir): Installer
    {
        // A token only matters for the API fallback (60/hour per IP without
        // one); the redirect the resolver tries first needs none. Read here
        // rather than in Installer so the plugin owns every getenv().
        $token = getenv('GITHUB_TOKEN');
        if (false === $token || '' === $token) {
            $token = getenv('GH_TOKEN');
        }
        $token = false === $token ? '' : $token;

        return new Installer(
            new Release(),
            $binDir,
            \dirname($binDir).'/tmp/'.Installer::SCRATCH_PREFIX.(int) getmypid(),
            token: $token,
            onProgress: function (InstallStep $step, int $percent) use ($api): void {
                $this->showProgress($api, $step, $percent);
            },
        );
    }

    private function showProgress(PluginApi $api, InstallStep $step, int $percent): void
    {
        $this->progress = match ($step) {
            InstallStep::Resolving => $api->trans('symfony-lsp: resolving…'),
            InstallStep::Downloading => 0 === $percent
                ? $api->trans('symfony-lsp: downloading…')
                : $api->trans('symfony-lsp: downloading %percent%%', ['%percent%' => $percent]),
            InstallStep::Verifying => $api->trans('symfony-lsp: verifying…'),
            InstallStep::Extracting => $api->trans('symfony-lsp: extracting…'),
            InstallStep::Checking => $api->trans('symfony-lsp: checking the binary…'),
        };
        // The bar caches its assembled line, so a segment that moved between
        // frames has to say so or the old text stays put.
        $api->refreshStatusSegments();
    }

    /** Release the status cell — a finished install must not park a percentage in the bar. */
    private function endProgress(PluginApi $api): void
    {
        $this->installing = false;
        $this->progress = '';
        $api->refreshStatusSegments();
    }

    /**
     * Claim the single-flight slot, or say why not, and answer with the bin
     * directory to install into.
     *
     * Both verbs need exactly this preamble, and an install that ran while
     * another was mid-flight would race two renames over one path.
     */
    private function begin(PluginApi $api): ?string
    {
        if (null === $this->binDir) {
            $api->notify($api->trans('symfony-lsp install failed: %why%', ['%why%' => 'no home directory to install into']), ToastSeverity::Error, 4.0);

            return null;
        }
        if ($this->installing) {
            $api->flash($api->trans('%tool%: already running', ['%tool%' => $this->name()]));

            return null;
        }
        $this->installing = true;

        return $this->binDir;
    }

    private function install(PluginApi $api): void
    {
        $binDir = $this->begin($api);
        if (null !== $binDir) {
            $noTag = null;
            $firstInstall = '';
            $this->download($api, $binDir, $noTag, $firstInstall);
        }
    }

    /**
     * Update asks what is available BEFORE it downloads anything.
     *
     * The old entry was the install closure under another name: it fetched
     * ~9MB every time, because nothing recorded what was already there.
     */
    private function update(PluginApi $api): void
    {
        $binDir = $this->begin($api);
        if (null === $binDir) {
            return;
        }
        $api->flash($api->trans('symfony-lsp: checking for a newer release…'), 4.0);
        $this->installer($api, $binDir)->latestTag(function (?string $tag, string $why) use ($api, $binDir): void {
            $latest = null === $tag ? null : new Release()->versionOfTag($tag);
            if (null === $tag || null === $latest) {
                $this->endProgress($api);
                $api->notify($api->trans('symfony-lsp update failed: %why%', ['%why%' => $why]), ToastSeverity::Error, 4.0);

                return;
            }
            // Ask the BINARY before committing to ~9MB, rather than trusting a
            // cache filled at boot: a copy replaced from another window (or by
            // hand) since then would otherwise make "0.21.0 → 0.21.0" out of a
            // download that changed nothing. Free when nothing moved — probe()
            // short-circuits on a path that has already answered.
            $this->versions->probe(($this->resolve)(), function () use ($api, $binDir, $latest, $tag): void {
                // An unknown installed version cannot be compared, so it
                // downloads: the old behaviour, kept for the one case that
                // earns it rather than for every case.
                $was = $this->versions->known();
                if ($was === $latest) {
                    $this->endProgress($api);
                    $api->notify($api->trans('symfony-lsp is already up to date (%version%)', ['%version%' => $was]), ToastSeverity::Info, 4.0);

                    return;
                }
                $this->download($api, $binDir, $tag, $was);
            });
        });
    }

    /**
     * @param string|null $tag a release {@see Installer::latestTag()} already resolved, so the
     *                         update path does not pay for the same lookup twice
     * @param string      $was the version being replaced, '' on a first install — it only ever
     *                         words the toast
     */
    private function download(PluginApi $api, string $binDir, ?string $tag, string $was): void
    {
        $api->flash($api->trans('symfony-lsp: downloading the latest release…'), 4.0);
        $this->installer($api, $binDir)->install(
            \PHP_OS_FAMILY,
            php_uname('m'),
            function (string $path, string $version) use ($api, $was): void {
                $this->endProgress($api);
                // The smoke test already asked the binary what it is, so this
                // costs nothing and the settings row and status cell are
                // correct from the first frame after the install.
                $this->versions->remember($path, $version);
                // Relaunch explicitly rather than by writing a setting. The
                // old trick wrote binary:'' purely for the reconfigure side
                // effect, which put a key for a machine-global install into a
                // file that travels with the project — and only ever worked on
                // the FIRST install: an upgrade resolves to byte-identical
                // argv, so the pool's identical-map skip declined and the old
                // process kept serving. The binary is found through the
                // ~/.porta/bin fallback either way, so nothing needs writing.
                $api->relaunchLanguageServer();
                // Name the MOVE when there was one. "installed: <path>" after
                // an upgrade reads as though nothing had changed, which is the
                // complaint the Update entry was added for.
                $api->notify(
                    '' !== $was && '' !== $version && $was !== $version
                        ? $api->trans('symfony-lsp updated: %from% → %to%', ['%from%' => $was, '%to%' => $version])
                        : $api->trans('symfony-lsp installed: %path%', ['%path%' => $path]),
                    ToastSeverity::Success,
                    4.0,
                );
            },
            function (string $why) use ($api): void {
                $this->endProgress($api);
                $api->notify($api->trans('symfony-lsp install failed: %why%', ['%why%' => $why]), ToastSeverity::Error, 4.0);
            },
            $tag,
        );
    }

    /**
     * The once-a-day "there is a newer server" notice.
     *
     * Nothing used to look: you found out a release existed by running Update
     * on the off-chance, which downloaded ~9MB whatever the answer was.
     */
    private function checkForUpdates(PluginApi $api): void
    {
        // Nothing installed is not "out of date" — that is the Install
        // command's business, and the Welcome card's. Offering an UPDATE for a
        // server that is not there would be noise.
        if (null === $this->binDir || '' === $this->versions->known() || !(bool) $api->setting('check-updates')) {
            return;
        }
        $now = \time();
        $check = new UpdateCheck(new Filesystem(), $this->binDir);
        if (!$check->due($now)) {
            return;
        }
        $this->installer($api, $this->binDir)->latestTag(function (?string $tag, string $why) use ($api, $check, $now): void {
            $latest = null === $tag ? null : new Release()->versionOfTag($tag);
            // Silence on failure, deliberately. A background check that toasts
            // "cannot reach github.com" at every launch on a plane is worse
            // than no check at all — the user asked for nothing here.
            if (null === $tag || null === $latest) {
                return;
            }
            // Recorded whatever the comparison says: the point of the note is
            // that this machine LOOKED today, not that it found something.
            $check->record($now, $tag);
            if ($latest !== $this->versions->known()) {
                $api->notify($api->trans('symfony-lsp %version% is available — run "Symfony LSP: Update Server"', ['%version%' => $latest]), ToastSeverity::Info, 6.0);
            }
        });
    }
};
