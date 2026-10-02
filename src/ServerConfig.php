<?php

declare(strict_types=1);

namespace Porta\SymfonyLsp;

use Porta\Plugin\Api\ServerNotFound;
use Porta\Support\Argv;

/**
 * What the plugin file wires and this class decides: which of the claimed
 * languages actually launch the server, and the `initializationOptions`
 * payload built from the plugin's settings.
 *
 * `workspaceTrust` is always sent explicitly, derived from the
 * runtime-indexing setting: left absent, the server would ask for a trust
 * decision through a prompt Porta cannot answer and sit in static-only mode
 * either way — so the setting IS the decision, off by default because trusting
 * a workspace means the server executes its PHP.
 */
final class ServerConfig
{
    /** Every language the plugin registers; the `languages` setting narrows the claim live. */
    public const array LANGUAGES = ['php', 'twig', 'yaml', 'xml', 'json', 'dotenv'];

    public const string DEFAULT_LANGUAGES = 'php, twig, yaml, xml, json, dotenv';

    /**
     * The launch argv for one language; null when the `languages` setting no
     * longer claims it (`'*'` claims everything); {@see ServerNotFound} when it
     * does and the binary is missing.
     *
     * @param list<string> $claimed
     *
     * @return list<string>|ServerNotFound|null
     */
    public function command(?string $binary, array $claimed, string $language): array|ServerNotFound|null
    {
        if (!\in_array($language, $claimed, strict: true) && !\in_array('*', $claimed, strict: true)) {
            return null;
        }
        if (null === $binary) {
            return new ServerNotFound();
        }

        return [$binary];
    }

    /**
     * The per-user install location (`<home>/.porta/bin`), PORTA_HOME winning
     * over HOME — the same pair core's global-plugin dir resolves — or null
     * when neither exists. Null deliberately does NOT fall back to the shared
     * system temp dir: the install's whole point is a user-owned, verified
     * landing spot, and /tmp would reopen the symlink-swap window.
     */
    public function binDir(string|false $portaHome, string|false $home): ?string
    {
        $base = \is_string($portaHome) && '' !== $portaHome ? $portaHome : $home;

        return \is_string($base) && '' !== $base ? $base.'/.porta/bin' : null;
    }

    /**
     * The binary to launch: the configured/discovered one when it can work,
     * else the copy a previous "Install Server" run landed in $binDir.
     *
     * A bare name (no directory separator) passes through untouched — the
     * spawn PATH-searches it, exactly as phpantom/phpactor treat their
     * settings, and is_executable() would wrongly test it against the CWD.
     * A PATHED value must actually exist: that check is what keeps a stale
     * `binary` setting — a teammate's absolute path riding in on a committed
     * .porta.json, a moved file — from shadowing the working fallbacks and
     * wedging every language at "starting…".
     */
    public function binary(?string $configured, ?string $binDir): ?string
    {
        if (null !== $configured && (!\str_contains($configured, '/') || \is_executable($configured))) {
            return $configured;
        }

        return null === $binDir ? null : $this->installed($binDir);
    }

    /**
     * The binary a previous "Install Server" run landed in $binDir, or null —
     * the resolver's fallback when neither the setting nor the PATH has one.
     */
    public function installed(string $binDir): ?string
    {
        $path = $binDir.'/symfony-lsp';

        return \is_executable($path) ? $path : null;
    }

    /**
     * The `initializationOptions` for the initialize handshake, from the
     * plugin's settings. Blank settings fall back to the server's own
     * defaults (`php`, `dev`) rather than sending empty values.
     *
     * @return array<string, mixed>
     */
    public function initializationOptions(string $phpCommand, string $environment, bool $runtimeIndexing): array
    {
        $php = Argv::split($phpCommand);
        $environment = \trim($environment);

        return [
            'phpCommand' => [] === $php ? ['php'] : $php,
            'environment' => '' === $environment ? 'dev' : $environment,
            'runtimeIndexing' => $runtimeIndexing,
            'workspaceTrust' => $runtimeIndexing,
        ];
    }
}
