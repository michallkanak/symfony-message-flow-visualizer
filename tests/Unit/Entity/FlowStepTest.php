<?php

declare(strict_types=1);

namespace MichalKanak\MessageFlowVisualizerBundle\Tests\Unit\Entity;

use MichalKanak\MessageFlowVisualizerBundle\Entity\FlowStep;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FlowStepTest extends TestCase
{
    public function testCreateFlowStep(): void
    {
        $step = new FlowStep('App\\Message\\TestMessage');

        $this->assertNotEmpty($step->getId());
        $this->assertSame('App\\Message\\TestMessage', $step->getMessageClass());
        $this->assertSame(FlowStep::TRANSPORT_SYNC, $step->getTransport());
        $this->assertFalse($step->isAsync());
        $this->assertSame(FlowStep::STATUS_PENDING, $step->getStatus());
    }

    public function testCreateAsyncFlowStep(): void
    {
        $step = new FlowStep(
            messageClass: 'App\\Message\\AsyncMessage',
            transport: 'doctrine',
            isAsync: true,
        );

        $this->assertSame('doctrine', $step->getTransport());
        $this->assertTrue($step->isAsync());
    }

    public function testMarkHandled(): void
    {
        $step = new FlowStep('App\\Message\\TestMessage');
        $step->markHandled();

        $this->assertSame(FlowStep::STATUS_HANDLED, $step->getStatus());
        $this->assertNotNull($step->getHandledAt());
        $this->assertNotNull($step->getProcessingDurationMs());
    }

    public function testMarkFailed(): void
    {
        $step = new FlowStep('App\\Message\\TestMessage');
        $exception = new RuntimeException('Test error');

        $step->markFailed($exception);

        $this->assertSame(FlowStep::STATUS_FAILED, $step->getStatus());
        $this->assertSame(RuntimeException::class, $step->getExceptionClass());
        $this->assertSame('Test error', $step->getExceptionMessage());
    }

    public function testMarkReceivedCalculatesQueueWait(): void
    {
        $step = new FlowStep(
            messageClass: 'App\\Message\\AsyncMessage',
            transport: 'doctrine',
            isAsync: true,
        );

        // Simulate time passing
        usleep(1000); // 1ms

        $step->markReceived();

        $this->assertNotNull($step->getReceivedAt());
        $this->assertNotNull($step->getQueueWaitDurationMs());
        $this->assertGreaterThanOrEqual(0, $step->getQueueWaitDurationMs());
    }

    public function testIncrementRetryCount(): void
    {
        $step = new FlowStep('App\\Message\\TestMessage');

        $this->assertSame(0, $step->getRetryCount());

        $step->incrementRetryCount();

        $this->assertSame(1, $step->getRetryCount());
        $this->assertSame(FlowStep::STATUS_RETRIED, $step->getStatus());
    }

    public function testSetParentStepId(): void
    {
        $step = new FlowStep('App\\Message\\TestMessage');
        $step->setParentStepId('parent-123');

        $this->assertSame('parent-123', $step->getParentStepId());
    }

    public function testSetFlowRunId(): void
    {
        $step = new FlowStep('App\\Message\\TestMessage');
        $step->setFlowRunId('run-456');

        $this->assertSame('run-456', $step->getFlowRunId());
    }

    public function testToArrayAndFromArray(): void
    {
        $step = new FlowStep('App\\Message\\TestMessage', 'doctrine', true);
        $step->setFlowRunId('run-id');
        $step->setParentStepId('parent-id');
        $step->setHandlerClass('App\\Handler\\TestHandler');
        $step->markReceived();
        $step->markHandled();

        $array = $step->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('messageClass', $array);
        $this->assertArrayHasKey('transport', $array);
        $this->assertArrayHasKey('isAsync', $array);
        $this->assertArrayHasKey('processingDurationMs', $array);
        $this->assertArrayHasKey('queueWaitDurationMs', $array);
        $this->assertArrayHasKey('totalDurationMs', $array);

        $restored = FlowStep::fromArray($array);

        $this->assertSame($step->getId(), $restored->getId());
        $this->assertSame($step->getMessageClass(), $restored->getMessageClass());
        $this->assertSame($step->getTransport(), $restored->getTransport());
        $this->assertSame($step->isAsync(), $restored->isAsync());
    }
}
