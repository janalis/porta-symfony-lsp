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
        $this->assertNull($release->latestTag('not json'));
        $this->assertNull($release->latestTag('{"name":"no tag"}'));
        $this->assertNull($release->latestTag('{"tag_name":""}'));
        $this->assertNull($release->latestTag('{"tag_name":42}'));
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
        $sha = str_repeat('ab', times: 32);
        $sums = "{$sha}  one.tar.gz\n".str_repeat('cd', times: 32)."  *two.tar.gz\nnot a sums line\nshort0  three.tar.gz\n";

        $this->assertSame($sha, $release->expectedSha($sums, 'one.tar.gz'));
        $this->assertSame(str_repeat('cd', times: 32), $release->expectedSha($sums, 'two.tar.gz'), 'the binary-mode * marker is stripped');
        $this->assertNull($release->expectedSha($sums, 'three.tar.gz'), 'a malformed hash is not trusted');
        $this->assertNull($release->expectedSha($sums, 'absent.tar.gz'));
        $this->assertNull($release->expectedSha('', 'one.tar.gz'));
    }
}
