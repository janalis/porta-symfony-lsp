<?php

declare(strict_types=1);

namespace Porta\SymfonyLsp;

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
     * The launch argv for one language, or null when the binary is missing or
     * the `languages` setting no longer claims it (`'*'` claims everything).
     *
     * @param list<string> $claimed
     *
     * @return list<string>|null
     */
    public function command(?string $binary, array $claimed, string $language): ?array
    {
        if (null === $binary) {
            return null;
        }
        if (!\in_array($language, $claimed, strict: true) && !\in_array('*', $claimed, strict: true)) {
            return null;
        }

        return [$binary];
    }

    /**
     * The binary a previous "Install Server" run landed in $binDir, or null —
     * the resolver's fallback when neither the setting nor the PATH has one.
     */
    public function installed(string $binDir): ?string
    {
        $path = $binDir.'/symfony-lsp';

        return is_executable($path) ? $path : null;
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
        $environment = trim($environment);

        return [
            'phpCommand' => [] === $php ? ['php'] : $php,
            'environment' => '' === $environment ? 'dev' : $environment,
            'runtimeIndexing' => $runtimeIndexing,
            'workspaceTrust' => $runtimeIndexing,
        ];
    }
}
