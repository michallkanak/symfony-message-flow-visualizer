<?php

declare(strict_types=1);

namespace MichalKanak\MessageFlowVisualizerBundle\Tests\Unit\Stamp;

use MichalKanak\MessageFlowVisualizerBundle\Stamp\TraceStamp;
use PHPUnit\Framework\TestCase;

class TraceStampTest extends TestCase
{
    public function testCreateTraceStamp(): void
    {
        $stamp = new TraceStamp(
            traceId: 'trace-123',
            flowRunId: 'run-456',
            parentStepId: 'step-789',
            correlationId: 'corr-abc',
            causationId: 'cause-def',
            isSampled: true,
        );

        $this->assertSame('trace-123', $stamp->traceId);
        $this->assertSame('run-456', $stamp->flowRunId);
        $this->assertSame('step-789', $stamp->parentStepId);
        $this->assertSame('corr-abc', $stamp->correlationId);
        $this->assertSame('cause-def', $stamp->causationId);
        $this->assertTrue($stamp->isSampled);
    }

    public function testCreateChild(): void
    {
        $parent = new TraceStamp(
            traceId: 'trace-123',
            flowRunId: 'run-456',
            parentStepId: null,
            correlationId: 'corr-abc',
            isSampled: true,
        );

        $child = $parent->createChild('new-step-id');

        // Same trace and flow
        $this->assertSame('trace-123', $child->traceId);
        $this->assertSame('run-456', $child->flowRunId);

        // Parent step is set
        $this->assertSame('new-step-id', $child->parentStepId);

        // Causation is the new step
        $this->assertSame('new-step-id', $child->causationId);

        // Correlation preserved
        $this->assertSame('corr-abc', $child->correlationId);

        // Sampling preserved
        $this->assertTrue($child->isSampled);
    }

    public function testCreateChildPreservesSampledFalse(): void
    {
        $parent = new TraceStamp(
            traceId: 'trace-123',
            flowRunId: 'run-456',
            isSampled: false,
        );

        $child = $parent->createChild('step-id');

        $this->assertFalse($child->isSampled);
    }

    public function testDefaultValues(): void
    {
        $stamp = new TraceStamp(
            traceId: 'trace-123',
            flowRunId: 'run-456',
        );

        $this->assertNull($stamp->parentStepId);
        $this->assertNull($stamp->correlationId);
        $this->assertNull($stamp->causationId);
        $this->assertTrue($stamp->isSampled); // Default is true
    }
}
