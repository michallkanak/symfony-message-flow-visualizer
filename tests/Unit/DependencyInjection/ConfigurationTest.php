<?php

declare(strict_types=1);

namespace MichalKanak\MessageFlowVisualizerBundle\Tests\Unit\DependencyInjection;

use MichalKanak\MessageFlowVisualizerBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

/**
 * Regression test: the 'enabled' option previously defaulted to
 * '%env(bool:MESSAGE_FLOW_ENABLED)%', which crashed at runtime
 * when the env var was not defined.
 */
class ConfigurationTest extends TestCase
{
    public function testEnabledDefaultsToTrueWithoutEnvVar(): void
    {
        $config = $this->processConfiguration([]);

        self::assertTrue($config['enabled']);
    }

    public function testEnabledCanBeSetToFalse(): void
    {
        $config = $this->processConfiguration(['enabled' => false]);

        self::assertFalse($config['enabled']);
    }

    public function testDefaultStorageIsFilesystem(): void
    {
        $config = $this->processConfiguration([]);

        self::assertSame('filesystem', $config['storage']['type']);
    }

    public function testDefaultSamplingIsDisabled(): void
    {
        $config = $this->processConfiguration([]);

        self::assertFalse($config['sampling']['enabled']);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function processConfiguration(array $input): array
    {
        return (new Processor())->processConfiguration(
            new Configuration(),
            [$input],
        );
    }
}
