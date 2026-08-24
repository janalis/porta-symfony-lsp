<?php

declare(strict_types=1);

namespace Porta\SymfonyLsp;

/**
 * Pure knowledge about symfony/language-tools' GitHub releases: which asset
 * fits this machine, where it downloads from, and how its checksum is read
 * out of the release's SHA256SUMS. No I/O — {@see Installer} does the doing,
 * which is what keeps every naming and parsing rule unit-testable.
 */
final class Release
{
    private const string REPO = 'symfony/language-tools';

    /**
     * The release platform token for a host, or null when no prebuilt binary
     * exists for it. $osFamily is PHP_OS_FAMILY, $machine is php_uname('m')
     * (macOS says arm64 where Linux says aarch64).
     */
    public function platform(string $osFamily, string $machine): ?string
    {
        $os = match ($osFamily) {
            'Darwin' => 'macos',
            'Linux' => 'linux',
            default => null,
        };
        $arch = match ($machine) {
            'arm64', 'aarch64' => 'arm64',
            'x86_64', 'amd64' => 'x64',
            default => null,
        };

        return null === $os || null === $arch ? null : $os.'-'.$arch;
    }

    /** The latest release tag out of GitHub's releases/latest JSON, or null when unreadable. */
    public function latestTag(string $json): ?string
    {
        /** @var array{tag_name?: mixed}|bool|int|float|string|null $decoded */
        $decoded = json_decode($json, associative: true);
        if (!\is_array($decoded) || !\is_string($decoded['tag_name'] ?? null) || '' === $decoded['tag_name']) {
            return null;
        }

        return $decoded['tag_name'];
    }

    public function apiUrl(): string
    {
        return 'https://api.github.com/repos/'.self::REPO.'/releases/latest';
    }

    public function asset(string $tag, string $platform): string
    {
        return 'symfony-lsp-'.$tag.'-'.$platform.'.tar.gz';
    }

    public function assetUrl(string $tag, string $asset): string
    {
        return 'https://github.com/'.self::REPO.'/releases/download/'.$tag.'/'.$asset;
    }

    public function sumsUrl(string $tag): string
    {
        return $this->assetUrl($tag, 'SHA256SUMS');
    }

    /**
     * The sha256 SHA256SUMS declares for $asset, or null when the file does
     * not mention it. Lines are the coreutils shape: `<hex><spaces><name>`.
     */
    public function expectedSha(string $sums, string $asset): ?string
    {
        foreach (explode("\n", $sums) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (\is_array($parts) && 2 === \count($parts) && $asset === ltrim($parts[1], characters: '*') && 1 === preg_match('/^[0-9a-f]{64}$/', $parts[0])) {
                return $parts[0];
            }
        }

        return null;
    }
}
