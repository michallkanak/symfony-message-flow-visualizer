<?php

declare(strict_types=1);

namespace MichalKanak\MessageFlowVisualizerBundle\Tests\Unit\Storage;

use MichalKanak\MessageFlowVisualizerBundle\Entity\FlowRun;
use MichalKanak\MessageFlowVisualizerBundle\Entity\FlowStep;
use MichalKanak\MessageFlowVisualizerBundle\Storage\RedisStorage;
use MichalKanak\MessageFlowVisualizerBundle\Tests\Fixtures\FakeRedisClient;
use PHPUnit\Framework\TestCase;

/**
 * Tests that RedisStorage::mergeStepData() correctly preserves
 * queueWaitDurationMs when merging two versions of the same step.
 *
 * Regression test for field name mismatch: mergeStepData() referenced
 * 'queueWaitMs' while FlowStep::toArray() serializes 'queueWaitDurationMs',
 * causing queue wait timing to be silently lost during merges.
 */
class RedisStorageMergeStepDataTest extends TestCase
{
    public function testMergePreservesQueueWaitDurationMs(): void
    {
        $redis = new FakeRedisClient();
        $storage = new RedisStorage($redis);

        // First save: a pending step with no timing data
        $run = new FlowRun(id: 'run-1', initiator: 'test');
        $pendingStep = new FlowStep('App\\Message\\TestMessage', 'async', true, 'step-1');
        $pendingStep->setFlowRunId($run->getId());
        $run->addStep($pendingStep);
        $storage->saveFlowRun($run);

        // Second save: same step, now handled with timing data
        $handledStep = new FlowStep('App\\Message\\TestMessage', 'async', true, 'step-1');
        $handledStep->setFlowRunId('run-1');
        $handledStep->markReceived();
        $handledStep->setHandlerClass('App\\Handler\\TestHandler');
        $handledStep->markHandled();

        $handledRun = new FlowRun(id: 'run-1', initiator: 'test');
        $handledRun->addStep($handledStep);
        $storage->saveFlowRun($handledRun);

        // Read back the merged data
        $storedData = json_decode($redis->get('mfv:run:run-1') ?? '', true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(1, $storedData['steps']);

        $mergedStep = $storedData['steps'][0];
        self::assertSame(FlowStep::STATUS_HANDLED, $mergedStep['status']);
        self::assertSame('App\\Handler\\TestHandler', $mergedStep['handlerClass']);
        self::assertArrayHasKey('queueWaitDurationMs', $mergedStep);
        self::assertNotNull($mergedStep['queueWaitDurationMs'], 'queueWaitDurationMs must be preserved during merge');
    }
}
