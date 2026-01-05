<?php

declare(strict_types=1);

namespace MichalKanak\MessageFlowVisualizerBundle\Tests\Unit\Trace;

use MichalKanak\MessageFlowVisualizerBundle\Trace\SamplingDecider;
use PHPUnit\Framework\TestCase;

class SamplingDeciderTest extends TestCase
{
    public function testSamplingDisabledAlwaysReturnsTrue(): void
    {
        $decider = new SamplingDecider(enabled: false, rate: 0.0);

        // Should always return true when disabled
        for ($i = 0; $i < 100; ++$i) {
            $this->assertTrue($decider->shouldSample());
        }
    }

    public function testSamplingWithZeroRateNeverSamples(): void
    {
        $decider = new SamplingDecider(enabled: true, rate: 0.0);

        // Should always return false with 0% rate
        for ($i = 0; $i < 100; ++$i) {
            $this->assertFalse($decider->shouldSample());
        }
    }

    public function testSamplingWithFullRateAlwaysSamples(): void
    {
        $decider = new SamplingDecider(enabled: true, rate: 1.0);

        // Should always return true with 100% rate
        for ($i = 0; $i < 100; ++$i) {
            $this->assertTrue($decider->shouldSample());
        }
    }

    public function testSamplingRateIsApproximatelyCorrect(): void
    {
        $decider = new SamplingDecider(enabled: true, rate: 0.5);

        $sampled = 0;
        $iterations = 1000;

        for ($i = 0; $i < $iterations; ++$i) {
            if ($decider->shouldSample()) {
                ++$sampled;
            }
        }

        // With 50% rate, expect roughly 500 samples (allow 15% margin)
        $this->assertGreaterThan($iterations * 0.35, $sampled);
        $this->assertLessThan($iterations * 0.65, $sampled);
    }

    public function testIsEnabled(): void
    {
        $enabled = new SamplingDecider(enabled: true, rate: 0.5);
        $disabled = new SamplingDecider(enabled: false, rate: 0.5);

        $this->assertTrue($enabled->isEnabled());
        $this->assertFalse($disabled->isEnabled());
    }

    public function testGetRate(): void
    {
        $decider = new SamplingDecider(enabled: true, rate: 0.25);

        $this->assertSame(0.25, $decider->getRate());
    }
}
