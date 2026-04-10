<?php

declare(strict_types=1);

namespace MichalKanak\MessageFlowVisualizerBundle\Tests\Unit\Storage;

use MichalKanak\MessageFlowVisualizerBundle\Entity\FlowRun;
use MichalKanak\MessageFlowVisualizerBundle\Entity\FlowStep;
use MichalKanak\MessageFlowVisualizerBundle\Storage\FilesystemStorage;
use PHPUnit\Framework\TestCase;

/**
 * Tests that FilesystemStorage::mergeStepData() correctly preserves
 * queueWaitDurationMs when merging two versions of the same step.
 *
 * Regression test for field name mismatch: mergeStepData() referenced
 * 'queueWaitMs' while FlowStep::toArray() serializes 'queueWaitDurationMs',
 * causing queue wait timing to be silently lost during merges.
 */
class FilesystemStorageMergeStepDataTest extends TestCase
{
    private string $storagePath;
    private FilesystemStorage $storage;

    protected function setUp(): void
    {
        $this->storagePath = sys_get_temp_dir().'/mfv_test_'.bin2hex(random_bytes(8));
        mkdir($this->storagePath, 0o755, true);
        $this->storage = new FilesystemStorage($this->storagePath);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storagePath);
    }

    public function testMergePreservesQueueWaitDurationMs(): void
    {
        // First save: a pending step with no timing data
        $run = new FlowRun(id: 'run-1', initiator: 'test');
        $pendingStep = new FlowStep('App\\Message\\TestMessage', 'async', true, 'step-1');
        $pendingStep->setFlowRunId($run->getId());
        $run->addStep($pendingStep);
        $this->storage->saveFlowRun($run);

        // Second save: same step, now handled with timing data
        // Simulate what happens when a worker processes the message:
        // the step gets receivedAt, handledAt, and duration metrics
        $handledStep = new FlowStep('App\\Message\\TestMessage', 'async', true, 'step-1');
        $handledStep->setFlowRunId('run-1');
        $handledStep->markReceived();
        $handledStep->setHandlerClass('App\\Handler\\TestHandler');
        $handledStep->markHandled();

        $handledRun = new FlowRun(id: 'run-1', initiator: 'test');
        $handledRun->addStep($handledStep);
        $this->storage->saveFlowRun($handledRun);

        // Verify: the merged data must contain queueWaitDurationMs
        $mergedRun = $this->storage->findFlowRun('run-1');
        self::assertNotNull($mergedRun);

        $steps = $mergedRun->getSteps();
        self::assertCount(1, $steps);

        $mergedStep = $steps[0];
        self::assertSame('App\\Handler\\TestHandler', $mergedStep->getHandlerClass());
        self::assertSame(FlowStep::STATUS_HANDLED, $mergedStep->getStatus());
        // The critical assertion: queueWaitDurationMs must not be null for async steps
        self::assertNotNull($mergedStep->getQueueWaitDurationMs(), 'queueWaitDurationMs must be preserved during merge');
    }

    public function testMergePreservesTimingFromHigherPriorityStep(): void
    {
        // Save a handled step with full timing
        $run = new FlowRun(id: 'run-2', initiator: 'test');
        $handledStep = new FlowStep('App\\Message\\TestMessage', 'async', true, 'step-2');
        $handledStep->setFlowRunId($run->getId());
        $handledStep->markReceived();
        $handledStep->setHandlerClass('App\\Handler\\TestHandler');
        $handledStep->markHandled();
        $run->addStep($handledStep);
        $this->storage->saveFlowRun($run);

        // Save again with a pending version of the same step (lower priority)
        $pendingRun = new FlowRun(id: 'run-2', initiator: 'test');
        $pendingStep = new FlowStep('App\\Message\\TestMessage', 'async', true, 'step-2');
        $pendingStep->setFlowRunId($pendingRun->getId());
        $pendingRun->addStep($pendingStep);
        $this->storage->saveFlowRun($pendingRun);

        // Verify: the handled data (higher priority) is preserved
        $mergedRun = $this->storage->findFlowRun('run-2');
        self::assertNotNull($mergedRun);

        $steps = $mergedRun->getSteps();
        self::assertCount(1, $steps);
        self::assertSame(FlowStep::STATUS_HANDLED, $steps[0]->getStatus());
        self::assertSame('App\\Handler\\TestHandler', $steps[0]->getHandlerClass());
        self::assertNotNull($steps[0]->getProcessingDurationMs());
        self::assertNotNull($steps[0]->getTotalDurationMs());
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if (false === $items) {
            return;
        }

        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $fullPath = $path.'/'.$item;
            if (is_dir($fullPath)) {
                $this->removeDirectory($fullPath);
            } else {
                unlink($fullPath);
            }
        }

        rmdir($path);
    }
}
