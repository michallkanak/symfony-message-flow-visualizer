<?php

declare(strict_types=1);

namespace MichalKanak\MessageFlowVisualizerBundle\Trace;

/**
 * Decides whether a new flow should be sampled for tracing.
 *
 * When sampling is enabled, only a percentage of flows are tracked.
 * Once a flow is sampled, ALL subsequent messages in that flow are tracked
 * (sampling continuity via TraceStamp.isSampled).
 */
class SamplingDecider
{
    public function __construct(
        private readonly bool $enabled,
        private readonly float $rate,
    ) {
    }

    /**
     * Decide if a new root flow should be sampled.
     *
     * If sampling is disabled, always returns true.
     * If enabled, returns true with probability = rate.
     */
    public function shouldSample(): bool
    {
        if (!$this->enabled) {
            return true;
        }

        return (random_int(0, PHP_INT_MAX) / PHP_INT_MAX) < $this->rate;
    }

    /**
     * Check if sampling is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the sampling rate.
     */
    public function getRate(): float
    {
        return $this->rate;
    }
}
