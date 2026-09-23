<?php

declare(strict_types=1);

namespace Porta\SymfonyLsp\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\SymfonyLsp\Release;

final class ReleaseTest extends TestCase
{
    /** @return iterable<string, array{string, string, string|null}> */
    public static function hosts(): iterable
    {
        yield 'mac arm' => ['Darwin', 'arm64', 'macos-arm64'];
        yield 'mac intel' => ['Darwin', 'x86_64', 'macos-x64'];
        yield 'linux arm' => ['Linux', 'aarch64', 'linux-arm64'];
        yield 'linux x64' => ['Linux', 'x86_64', 'linux-x64'];
        yield 'linux amd64 alias' => ['Linux', 'amd64', 'linux-x64'];
        yield 'windows has its own zip, not handled' => ['Windows', 'x86_64', null];
        yield 'exotic arch' => ['Linux', 'riscv64', null];
    }

    #[DataProvider('hosts')]
    public function testPlatformMapsOsAndMachine(string $os, string $machine, ?string $platform): void
    {
        $this->assertSame($platform, new Release()->platform($os, $machine));
    }

    public function testLatestTagReadsTheReleaseJsonDefensively(): void
    {
        $release = new Release();

        $this->assertSame('v0.16.0', $release->latestTag('{"tag_name":"v0.16.0","name":"x"}'));
        $this->assertSame('v1.0.0-rc.1', $release->latestTag('{"tag_name":"v1.0.0-rc.1"}'), 'pre-release tags are still tags');
        $this->assertNull($release->latestTag('not json'));
        $this->assertNull($release->latestTag('{"name":"no tag"}'));
        $this->assertNull($release->latestTag('{"tag_name":""}'));
        $this->assertNull($release->latestTag('{"tag_name":42}'));
        $this->assertNull($release->latestTag('{"tag_name":"../../evil"}'), 'the tag becomes a URL segment and a filename — traversal dies here');
        $this->assertNull($release->latestTag('{"tag_name":"v1/../x"}'));
    }

    public function testUrlsAndAssetNamesComposeFromTagAndPlatform(): void
    {
        $release = new Release();

        $this->assertSame('https://api.github.com/repos/symfony/language-tools/releases/latest', $release->apiUrl());
        $this->assertSame('symfony-lsp-v0.16.0-macos-arm64.tar.gz', $release->asset('v0.16.0', 'macos-arm64'));
        $this->assertSame('https://github.com/symfony/language-tools/releases/download/v0.16.0/symfony-lsp-v0.16.0-macos-arm64.tar.gz', $release->assetUrl(
            'v0.16.0',
            'symfony-lsp-v0.16.0-macos-arm64.tar.gz',
        ));
        $this->assertSame('https://github.com/symfony/language-tools/releases/download/v0.16.0/SHA256SUMS', $release->sumsUrl('v0.16.0'));
    }

    public function testExpectedShaFindsTheAssetLineAndRejectsTheRest(): void
    {
        $release = new Release();
        $sha = \str_repeat('ab', times: 32);
        $sums = "{$sha}  one.tar.gz\n".\str_repeat('cd', times: 32)."  *two.tar.gz\nnot a sums line\nshort0  three.tar.gz\n";

        $this->assertSame($sha, $release->expectedSha($sums, 'one.tar.gz'));
        $this->assertSame(\str_repeat('cd', times: 32), $release->expectedSha($sums, 'two.tar.gz'), 'the binary-mode * marker is stripped');
        $this->assertNull($release->expectedSha($sums, 'three.tar.gz'), 'a malformed hash is not trusted');
        $this->assertNull($release->expectedSha($sums, 'absent.tar.gz'));
        $this->assertNull($release->expectedSha('', 'one.tar.gz'));
    }

    /**
     * The `--version` line is the only record of what is installed — there is
     * no sidecar file and no setting, because either would be a second source
     * of truth that desyncs when someone swaps the binary by hand.
     */
    public function testVersionFromReadsWhatTheBinaryCallsItself(): void
    {
        $release = new Release();

        $this->assertSame('0.21.0', $release->versionFrom("Symfony Language Tools 0.21.0\n"));
        $this->assertSame('1.0.0-beta.2', $release->versionFrom('Symfony Language Tools 1.0.0-beta.2'), 'a pre-release still names itself');
        $this->assertNull($release->versionFrom('Symfony Language Tools'), 'a line with no version is not a version');
        $this->assertNull($release->versionFrom(''), 'a binary that printed nothing leaves the version unknown');
        $this->assertNull($release->versionFrom('v9.9.9'), 'a TAG is not a --version line; versionOfTag() is that direction');
    }

    /**
     * A tag and a `--version` line must compare as one spelling, or an update
     * check answers "0.21.0 is not v0.21.0" and downloads 9MB on every run.
     */
    public function testVersionOfTagStripsTheTagSpelling(): void
    {
        $release = new Release();

        $this->assertSame('0.21.0', $release->versionOfTag('v0.21.0'));
        $this->assertSame('1.0.0-rc.1', $release->versionOfTag('v1.0.0-rc.1'));
        $this->assertNull($release->versionOfTag('0.21.0'), 'a tag without its v is not a tag this repo publishes');
        $this->assertNull($release->versionOfTag('../../evil'), 'the same guard the resolvers share');
        $this->assertNull($release->versionOfTag('v'), 'a bare v carries no version to compare');
    }
}
