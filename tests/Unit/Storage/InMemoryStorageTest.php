<?php

declare(strict_types=1);

namespace MichalKanak\MessageFlowVisualizerBundle\Tests\Unit\Storage;

use DateTimeImmutable;
use MichalKanak\MessageFlowVisualizerBundle\Entity\FlowRun;
use MichalKanak\MessageFlowVisualizerBundle\Entity\FlowStep;
use MichalKanak\MessageFlowVisualizerBundle\Storage\InMemoryStorage;
use PHPUnit\Framework\TestCase;

class InMemoryStorageTest extends TestCase
{
    private InMemoryStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
    }

    public function testSaveAndFindFlowRun(): void
    {
        $flowRun = new FlowRun();
        $this->storage->saveFlowRun($flowRun);

        $found = $this->storage->findFlowRun($flowRun->getId());

        $this->assertNotNull($found);
        $this->assertSame($flowRun->getId(), $found->getId());
    }

    public function testFindFlowRunByTraceId(): void
    {
        $flowRun = new FlowRun();
        $this->storage->saveFlowRun($flowRun);

        $found = $this->storage->findFlowRunByTraceId($flowRun->getTraceId());

        $this->assertNotNull($found);
        $this->assertSame($flowRun->getTraceId(), $found->getTraceId());
    }

    public function testFindNonExistentFlowRun(): void
    {
        $found = $this->storage->findFlowRun('non-existent-id');

        $this->assertNull($found);
    }

    public function testSaveFlowStep(): void
    {
        $flowRun = new FlowRun();
        $this->storage->saveFlowRun($flowRun);

        $step = new FlowStep('App\\Message\\TestMessage');
        $step->setFlowRunId($flowRun->getId());

        $this->storage->saveFlowStep($step);

        $steps = $this->storage->findStepsByFlowRun($flowRun->getId());

        $this->assertCount(1, $steps);
        $this->assertSame($step->getId(), $steps[0]->getId());
    }

    public function testFindRecentFlowRuns(): void
    {
        $run1 = new FlowRun();
        $run2 = new FlowRun();
        $run3 = new FlowRun();

        $this->storage->saveFlowRun($run1);
        $this->storage->saveFlowRun($run2);
        $this->storage->saveFlowRun($run3);

        $recent = $this->storage->findRecentFlowRuns(2);

        $this->assertCount(2, $recent);
    }

    public function testFindRecentFlowRunsFilterByStatus(): void
    {
        $run1 = new FlowRun();
        $run1->markCompleted();

        $run2 = new FlowRun();
        $run2->markFailed();

        $this->storage->saveFlowRun($run1);
        $this->storage->saveFlowRun($run2);

        $completed = $this->storage->findRecentFlowRuns(10, FlowRun::STATUS_COMPLETED);
        $failed = $this->storage->findRecentFlowRuns(10, FlowRun::STATUS_FAILED);

        $this->assertCount(1, $completed);
        $this->assertCount(1, $failed);
    }

    public function testCleanup(): void
    {
        $oldRun = new FlowRun();
        $this->storage->saveFlowRun($oldRun);

        // Cleanup with future date should delete
        $deleted = $this->storage->cleanup(new DateTimeImmutable('+1 day'));

        $this->assertSame(1, $deleted);
        $this->assertNull($this->storage->findFlowRun($oldRun->getId()));
    }

    public function testGetStatistics(): void
    {
        $run1 = new FlowRun();
        $run1->markCompleted();

        $run2 = new FlowRun();
        $run2->markFailed();

        $step = new FlowStep('App\\Message\\TestMessage');
        $step->setFlowRunId($run1->getId());
        $run1->addStep($step);

        $this->storage->saveFlowRun($run1);
        $this->storage->saveFlowRun($run2);

        $stats = $this->storage->getStatistics(
            new DateTimeImmutable('-1 hour'),
            new DateTimeImmutable('+1 hour'),
        );

        $this->assertSame(2, $stats['totalFlows']);
        $this->assertSame(1, $stats['completedFlows']);
        $this->assertSame(1, $stats['failedFlows']);
        $this->assertArrayHasKey('App\\Message\\TestMessage', $stats['messageClasses']);
    }

    public function testClear(): void
    {
        $this->storage->saveFlowRun(new FlowRun());
        $this->storage->saveFlowRun(new FlowRun());

        $this->storage->clear();

        $recent = $this->storage->findRecentFlowRuns(100);
        $this->assertCount(0, $recent);
    }

    public function testFindRecentFlowRunsPaginated(): void
    {
        // Create 5 flow runs
        for ($i = 0; $i < 5; ++$i) {
            $run = new FlowRun();
            usleep(1000); // Ensure different timestamps
            $this->storage->saveFlowRun($run);
        }

        // Page 1: Get first 2 items
        $result = $this->storage->findRecentFlowRunsPaginated(2, 0);
        $this->assertCount(2, $result->items);
        $this->assertSame(5, $result->total);
        $this->assertSame(1, $result->getPage());
        $this->assertSame(3, $result->getTotalPages());
        $this->assertTrue($result->hasMore());

        // Page 2: Get next 2 items
        $result2 = $this->storage->findRecentFlowRunsPaginated(2, 2);
        $this->assertCount(2, $result2->items);
        $this->assertSame(2, $result2->getPage());
        $this->assertTrue($result2->hasMore());

        // Page 3: Get last item
        $result3 = $this->storage->findRecentFlowRunsPaginated(2, 4);
        $this->assertCount(1, $result3->items);
        $this->assertSame(3, $result3->getPage());
        $this->assertFalse($result3->hasMore());
    }

    public function testFindRecentFlowRunsPaginatedWithStatusFilter(): void
    {
        $completedRun = new FlowRun();
        $completedRun->markCompleted();
        $failedRun = new FlowRun();
        $failedRun->markFailed();

        $this->storage->saveFlowRun($completedRun);
        $this->storage->saveFlowRun($failedRun);

        $result = $this->storage->findRecentFlowRunsPaginated(10, 0, FlowRun::STATUS_COMPLETED);

        $this->assertCount(1, $result->items);
        $this->assertSame(1, $result->total);
        $this->assertSame(FlowRun::STATUS_COMPLETED, $result->items[0]->getStatus());
    }
}
