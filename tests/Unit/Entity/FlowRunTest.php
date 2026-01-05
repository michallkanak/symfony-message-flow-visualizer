<?php

declare(strict_types=1);

namespace MichalKanak\MessageFlowVisualizerBundle\Tests\Unit\Entity;

use MichalKanak\MessageFlowVisualizerBundle\Entity\FlowRun;
use MichalKanak\MessageFlowVisualizerBundle\Entity\FlowStep;
use PHPUnit\Framework\TestCase;

class FlowRunTest extends TestCase
{
    public function testCreateFlowRun(): void
    {
        $run = new FlowRun();

        $this->assertNotEmpty($run->getId());
        $this->assertNotEmpty($run->getTraceId());
        $this->assertSame(FlowRun::STATUS_RUNNING, $run->getStatus());
        $this->assertNull($run->getFinishedAt());
    }

    public function testCreateFlowRunWithCustomValues(): void
    {
        $run = new FlowRun(
            id: 'custom-id',
            traceId: 'custom-trace',
            initiator: 'test-initiator',
        );

        $this->assertSame('custom-id', $run->getId());
        $this->assertSame('custom-trace', $run->getTraceId());
        $this->assertSame('test-initiator', $run->getInitiator());
    }

    public function testMarkCompleted(): void
    {
        $run = new FlowRun();
        $run->markCompleted();

        $this->assertSame(FlowRun::STATUS_COMPLETED, $run->getStatus());
        $this->assertNotNull($run->getFinishedAt());
    }

    public function testMarkFailed(): void
    {
        $run = new FlowRun();
        $run->markFailed();

        $this->assertSame(FlowRun::STATUS_FAILED, $run->getStatus());
        $this->assertNotNull($run->getFinishedAt());
    }

    public function testAddStep(): void
    {
        $run = new FlowRun();
        $step = new FlowStep('App\\Message\\TestMessage');

        $run->addStep($step);

        $this->assertCount(1, $run->getSteps());
        $this->assertSame($step, $run->getSteps()[0]);
    }

    public function testSetMetadata(): void
    {
        $run = new FlowRun();
        $run->setMetadata('key', 'value');

        $this->assertSame(['key' => 'value'], $run->getMetadata());
    }

    public function testToArrayAndFromArray(): void
    {
        $run = new FlowRun(initiator: 'test');
        $run->setMetadata('foo', 'bar');
        $run->markCompleted();

        $array = $run->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('traceId', $array);
        $this->assertArrayHasKey('status', $array);
        $this->assertArrayHasKey('startedAt', $array);
        $this->assertArrayHasKey('finishedAt', $array);
        $this->assertArrayHasKey('initiator', $array);
        $this->assertArrayHasKey('metadata', $array);

        $restored = FlowRun::fromArray($array);

        $this->assertSame($run->getId(), $restored->getId());
        $this->assertSame($run->getTraceId(), $restored->getTraceId());
        $this->assertSame($run->getStatus(), $restored->getStatus());
        $this->assertSame($run->getInitiator(), $restored->getInitiator());
    }
}
