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

    /**
     * The latest release tag out of GitHub's releases/latest JSON, or null
     * when unreadable — or when it does not look like a release tag. The tag
     * flows into the download URL and the on-disk tarball name, so a hostile
     * value (`../../x`) must die here, not become a path.
     */
    public function latestTag(string $json): ?string
    {
        /** @var array{tag_name?: mixed}|bool|int|float|string|null $decoded */
        $decoded = \json_decode($json, associative: true);
        $tag = \is_array($decoded) && \is_string($decoded['tag_name'] ?? null) ? $decoded['tag_name'] : '';

        return self::validTag($tag);
    }

    /**
     * The tag out of the `releases/latest` redirect's Location header, or null
     * when the header is absent or does not end in a release tag.
     *
     * Reads the LAST `location:` in the response, because the caller follows
     * redirects and earlier hops (http→https, a repo rename) carry their own.
     */
    public function tagFromLocation(string $headers): ?string
    {
        $matches = [];
        if (1 > (int) \preg_match_all('~^location:\s*(\S+)~mi', $headers, $matches)) {
            return null;
        }
        $last = (string) \end($matches[1]);
        $tag = \substr($last, (int) \strrpos($last, needle: '/') + 1);

        return \str_contains($last, '/releases/tag/') ? self::validTag($tag) : null;
    }

    /**
     * The web URL whose redirect names the latest release.
     *
     * Deliberately NOT `api.github.com`: that is 60 requests per hour per IP
     * unauthenticated, and spending the whole budget on a tag lookup is what
     * made "Install Server" fail with `could not resolve the latest
     * symfony-lsp release` while nothing was wrong. This is ordinary release
     * traffic on the host {@see assetUrl()} and {@see sumsUrl()} already use.
     */
    public function latestUrl(): string
    {
        return 'https://github.com/'.self::REPO.'/releases/latest';
    }

    public function apiUrl(): string
    {
        return 'https://api.github.com/repos/'.self::REPO.'/releases/latest';
    }

    /**
     * $tag when it looks like a release tag, else null.
     *
     * Shared by both resolvers so they cannot disagree: the tag flows into the
     * download URL and the on-disk tarball name, so a hostile value
     * (`../../x`) must die here rather than become a path.
     */
    private static function validTag(string $tag): ?string
    {
        return 1 === \preg_match('/^v[0-9][0-9A-Za-z.\-]*$/', $tag) ? $tag : null;
    }

    /**
     * The version the binary reports for itself, from its `--version` line
     * (`Symfony Language Tools 0.21.0` -> `0.21.0`), or null when the output
     * is not that shape.
     *
     * Bare, without the tag's leading `v`: this is what the BINARY says, and a
     * tag says `v0.21.0`. Comparisons normalise on the way in rather than
     * pretending the two spellings are the same string.
     */
    public function versionFrom(string $output): ?string
    {
        $matches = [];

        return 1 === \preg_match('/\b([0-9]+\.[0-9]+\.[0-9]+[0-9A-Za-z.\-]*)/', $output, $matches) ? $matches[1] : null;
    }

    /**
     * $tag as a bare version, so a tag and a `--version` line compare as one
     * spelling. A tag that is not shaped like one answers null rather than a
     * value that would silently never match.
     */
    public function versionOfTag(string $tag): ?string
    {
        $version = null === self::validTag($tag) ? null : \substr($tag, offset: 1);

        return null === $version || '' === $version ? null : $version;
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
        foreach (\explode("\n", $sums) as $line) {
            $parts = \preg_split('/\s+/', \trim($line));
            if (\is_array($parts) && 2 === \count($parts) && \ltrim($parts[1], characters: '*') === $asset && 1 === \preg_match('/^[0-9a-f]{64}$/', $parts[0])) {
                return $parts[0];
            }
        }

        return null;
    }
}
