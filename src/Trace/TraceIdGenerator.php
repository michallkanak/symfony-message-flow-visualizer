<?php

declare(strict_types=1);

namespace MichalKanak\MessageFlowVisualizerBundle\Trace;

use Symfony\Component\Uid\Uuid;

/**
 * Generator for trace, correlation, and causation IDs.
 */
class TraceIdGenerator
{
    /**
     * Generate a new trace ID (UUID v4).
     */
    public function generateTraceId(): string
    {
        return Uuid::v4()->toRfc4122();
    }

    /**
     * Generate a new flow run ID (UUID v4).
     */
    public function generateFlowRunId(): string
    {
        return Uuid::v4()->toRfc4122();
    }

    /**
     * Generate a new step ID (UUID v4).
     */
    public function generateStepId(): string
    {
        return Uuid::v4()->toRfc4122();
    }

    /**
     * Generate a correlation ID (UUID v4).
     */
    public function generateCorrelationId(): string
    {
        return Uuid::v4()->toRfc4122();
    }
}
