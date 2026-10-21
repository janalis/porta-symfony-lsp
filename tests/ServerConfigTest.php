<?php

declare(strict_types=1);

namespace Porta\SymfonyLsp\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Api\ServerNotFound;
use Porta\SymfonyLsp\ServerConfig;

final class ServerConfigTest extends TestCase
{
    public function testCommandLaunchesOnlyClaimedLanguagesAndNeedsABinary(): void
    {
        $config = new ServerConfig();

        $this->assertSame(['/opt/symfony-lsp'], $config->command('/opt/symfony-lsp', ['twig', 'yaml'], 'twig'));
        $this->assertNull($config->command('/opt/symfony-lsp', ['twig', 'yaml'], 'php'), 'an unclaimed language stays unserved');
        $this->assertInstanceOf(ServerNotFound::class, $config->command(null, ['twig'], 'twig'), 'claimed, no binary: not found');
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
