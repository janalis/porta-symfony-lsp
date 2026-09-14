<?php

declare(strict_types=1);

namespace Porta\SymfonyLsp\Tests;

use PHPUnit\Framework\TestCase;
use Porta\SymfonyLsp\ServerConfig;
use Porta\Tests\Support\TempProject;

final class ServerConfigTest extends TestCase
{
    public function testCommandLaunchesOnlyClaimedLanguagesAndNeedsABinary(): void
    {
        $config = new ServerConfig();

        $this->assertSame(['/opt/symfony-lsp'], $config->command('/opt/symfony-lsp', ['twig', 'yaml'], 'twig'));
        $this->assertNull($config->command('/opt/symfony-lsp', ['twig', 'yaml'], 'php'), 'an unclaimed language stays unserved');
        $this->assertNull($config->command(null, ['twig'], 'twig'), 'no binary, no server');
        $this->assertNull($config->command(null, [], 'twig'));
    }

    public function testAStarClaimServesEveryRegisteredLanguage(): void
    {
        $config = new ServerConfig();

        foreach (ServerConfig::LANGUAGES as $language) {
            $this->assertSame(['/opt/symfony-lsp'], $config->command('/opt/symfony-lsp', ['*'], $language));
        }
    }

    public function testTheDefaultLanguagesSettingClaimsExactlyTheRegisteredSet(): void
    {
        $claimed = \array_values(\array_filter(\array_map(trim(...), \explode(',', ServerConfig::DEFAULT_LANGUAGES))));

        $this->assertSame(ServerConfig::LANGUAGES, $claimed, 'the setting default and the registered set must not drift apart');
    }

    public function testBinDirIsUserOwnedOrNothing(): void
    {
        $config = new ServerConfig();

        $this->assertSame('/opt/porta-home/.porta/bin', $config->binDir('/opt/porta-home', '/home/u'), 'PORTA_HOME wins');
        $this->assertSame('/home/u/.porta/bin', $config->binDir(false, '/home/u'), 'HOME is the fallback');
        $this->assertSame('/home/u/.porta/bin', $config->binDir('', '/home/u'), 'an empty PORTA_HOME does not count');
        $this->assertNull($config->binDir(false, false), 'no home = no install location — never the shared /tmp');
        $this->assertNull($config->binDir('', ''));
    }

    public function testBinaryPrefersALiveConfiguredPathAndFallsToTheInstalledCopy(): void
    {
        $config = new ServerConfig();
        $scratch = new TempProject();
        \mkdir($scratch->path('bin'));
        \file_put_contents($scratch->path('bin/symfony-lsp'), data: "#!/bin/sh\nexit 0\n");
        \chmod($scratch->path('bin/symfony-lsp'), permissions: 0o755);
        $live = $scratch->path('live');
        \file_put_contents($live, data: "#!/bin/sh\nexit 0\n");
        \chmod($live, permissions: 0o755);

        $this->assertSame($live, $config->binary($live, $scratch->path('bin')), 'an existing configured/discovered binary wins');
        $this->assertSame($scratch->path('bin').'/symfony-lsp', $config->binary(null, $scratch->path('bin')), 'nothing configured: the installed copy serves');
        $this->assertSame(
            $scratch->path('bin').'/symfony-lsp',
            $config->binary('/Users/someone-else/.porta/bin/symfony-lsp', $scratch->path('bin')),
            'a dead configured path — a teammate\'s absolute path riding in on a committed .porta.json — must NOT shadow the working fallback',
        );
        $this->assertNull($config->binary('/nonexistent', null), 'no fallback dir, dead path: unserved');
        $this->assertNull($config->binary(null, null));
    }

    public function testABareBinaryNamePassesThroughForThePathSearch(): void
    {
        // A bare name has no directory to test against — is_executable()
        // would resolve it against the CWD — and the array-form spawn
        // PATH-searches it, exactly as phpantom/phpactor treat their setting.
        $config = new ServerConfig();

        $this->assertSame('symfony-lsp', $config->binary('symfony-lsp', null));
        $this->assertSame('my-wrapper', $config->binary('my-wrapper', '/nonexistent-bin'), 'the fallback is not consulted for a bare name');
        $this->assertNull($config->binary('~/bin/symfony-lsp', null), 'a pathed value that does not exist (tilde is not expanded) falls through');
    }

    public function testInitializationOptionsCarryTheSettingsWithExplicitTrust(): void
    {
        $options = new ServerConfig()->initializationOptions('php', 'dev', false);

        $this->assertSame(
            ['phpCommand' => ['php'], 'environment' => 'dev', 'runtimeIndexing' => false, 'workspaceTrust' => false],
            $options,
            'workspaceTrust is always sent — absent, the server would prompt and wedge in static mode',
        );
    }

    public function testRuntimeIndexingGrantsWorkspaceTrust(): void
    {
        $options = new ServerConfig()->initializationOptions('php', 'test', true);

        $this->assertTrue($options['runtimeIndexing']);
        $this->assertTrue($options['workspaceTrust'], 'the setting is the trust decision');
        $this->assertSame('test', $options['environment']);
    }

    public function testPhpCommandIsArgvSplitAndBlanksFallBackToServerDefaults(): void
    {
        $config = new ServerConfig();

        $this->assertSame(['symfony', 'php'], $config->initializationOptions('symfony php', 'dev', false)['phpCommand'], 'a multi-token command splits into argv');
        $this->assertSame(['/opt/my php', '-d', 'x=1'], $config->initializationOptions('"/opt/my php" -d x=1', 'dev', false)['phpCommand'], 'quoting survives');

        $blank = $config->initializationOptions('   ', "  \t", false);
        $this->assertSame(['php'], $blank['phpCommand'], 'a blank command falls back to php');
        $this->assertSame('dev', $blank['environment'], 'a blank environment falls back to dev');
    }
}
