<?php

declare(strict_types=1);

namespace MichalKanak\MessageFlowVisualizerBundle\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Stamp that carries trace context information through the message chain.
 *
 * This stamp is used to:
 * - Link related messages in a flow (parent-child relationships)
 * - Maintain sampling continuity (once sampled, always tracked)
 * - Provide correlation/causation IDs for distributed tracing
 * - Correlate dispatched and received async messages via stepId
 */
final class TraceStamp implements StampInterface
{
    public function __construct(
        public readonly string $traceId,
        public readonly string $flowRunId,
        public readonly ?string $parentStepId = null,
        public readonly ?string $stepId = null,
        public readonly ?string $correlationId = null,
        public readonly ?string $causationId = null,
        public readonly bool $isSampled = true,
    ) {
    }

    /**
     * Create a stamp with stepId for async message tracking.
     */
    public function withStepId(string $stepId): self
    {
        return new self(
            traceId: $this->traceId,
            flowRunId: $this->flowRunId,
            parentStepId: $this->parentStepId,
            stepId: $stepId,
            correlationId: $this->correlationId,
            causationId: $this->causationId,
            isSampled: $this->isSampled,
        );
    }

    /**
     * Create a child stamp for a dispatched message from a handler.
     */
    public function createChild(string $newStepId): self
    {
        return new self(
            traceId: $this->traceId,
            flowRunId: $this->flowRunId,
            parentStepId: $newStepId,
            stepId: null,
            correlationId: $this->correlationId,
            causationId: $newStepId,
            isSampled: $this->isSampled,
        );
    }
}
