<?php

declare(strict_types=1);

namespace MichalKanak\MessageFlowVisualizerBundle\Storage;

use DateTimeInterface;
use MichalKanak\MessageFlowVisualizerBundle\Entity\FlowRun;
use MichalKanak\MessageFlowVisualizerBundle\Entity\FlowStep;

/**
 * In-memory storage implementation for testing and synchronous-only flows.
 *
 * WARNING: This storage is NOT suitable for production use with async messages!
 * Data is stored only in PHP process memory and will be lost when the process ends.
 * Since Messenger workers run in separate processes, async message statuses will NOT
 * be updated when using this storage. Use FilesystemStorage, RedisStorage, or
 * DoctrineStorage for production environments with async message processing.
 *
 * Recommended use cases:
 * - Unit and integration testing
 * - Development/debugging with synchronous transport only
 * - Symfony Profiler integration (same-request data)
 */
class InMemoryStorage implements StorageInterface
{
    /** @var array<string, FlowRun> */
    private array $flowRuns = [];

    /** @var array<string, FlowStep> */
    private array $flowSteps = [];

    public function saveFlowRun(FlowRun $run): void
    {
        $this->flowRuns[$run->getId()] = $run;
    }

    public function saveFlowStep(FlowStep $step): void
    {
        $this->flowSteps[$step->getId()] = $step;

        // Also add to flow run
        $flowRunId = $step->getFlowRunId();
        if (null !== $flowRunId && isset($this->flowRuns[$flowRunId])) {
            $flowRun = $this->flowRuns[$flowRunId];

            // Check if step already exists
            $exists = false;
            foreach ($flowRun->getSteps() as $existingStep) {
                if ($existingStep->getId() === $step->getId()) {
                    $exists = true;
                    break;
                }
            }

            if (!$exists) {
                $flowRun->addStep($step);
            }
        }
    }

    public function findFlowRun(string $id): ?FlowRun
    {
        return $this->flowRuns[$id] ?? null;
    }

    public function findFlowRunByTraceId(string $traceId): ?FlowRun
    {
        foreach ($this->flowRuns as $run) {
            if ($run->getTraceId() === $traceId) {
                return $run;
            }
        }

        return null;
    }

    public function findStepsByFlowRun(string $flowRunId): array
    {
        $flowRun = $this->findFlowRun($flowRunId);
        if (null !== $flowRun) {
            return $flowRun->getSteps();
        }

        return array_filter(
            $this->flowSteps,
            fn (FlowStep $step) => $step->getFlowRunId() === $flowRunId,
        );
    }

    public function findRecentFlowRuns(int $limit = 50, ?string $status = null): array
    {
        $runs = array_values($this->flowRuns);

        if (null !== $status) {
            $runs = array_filter($runs, fn (FlowRun $run) => $run->getStatus() === $status);
        }

        usort($runs, fn (FlowRun $a, FlowRun $b) => $b->getStartedAt() <=> $a->getStartedAt());

        return \array_slice($runs, 0, $limit);
    }

    public function findRecentFlowRunsPaginated(
        int $limit = 50,
        int $offset = 0,
        ?string $status = null,
    ): PaginatedResult {
        $runs = array_values($this->flowRuns);

        if (null !== $status) {
            $runs = array_filter($runs, fn (FlowRun $run) => $run->getStatus() === $status);
            $runs = array_values($runs);
        }

        usort($runs, fn (FlowRun $a, FlowRun $b) => $b->getStartedAt() <=> $a->getStartedAt());

        $total = \count($runs);
        $items = \array_slice($runs, $offset, $limit);

        return new PaginatedResult($items, $total, $limit, $offset);
    }

    public function getStatistics(DateTimeInterface $from, DateTimeInterface $to): array
    {
        $stats = [
            'totalFlows' => 0,
            'completedFlows' => 0,
            'failedFlows' => 0,
            'avgDurationMs' => 0.0,
            'messageClasses' => [],
        ];

        $durations = [];

        foreach ($this->flowRuns as $run) {
            $startedAt = $run->getStartedAt();

            if ($startedAt < $from || $startedAt > $to) {
                continue;
            }

            ++$stats['totalFlows'];

            if (FlowRun::STATUS_COMPLETED === $run->getStatus()) {
                ++$stats['completedFlows'];
            } elseif (FlowRun::STATUS_FAILED === $run->getStatus()) {
                ++$stats['failedFlows'];
            }

            $duration = $run->getDurationMs();
            if (null !== $duration) {
                $durations[] = $duration;
            }

            foreach ($run->getSteps() as $step) {
                $messageClass = $step->getMessageClass();
                $stats['messageClasses'][$messageClass] = ($stats['messageClasses'][$messageClass] ?? 0) + 1;
            }
        }

        if (\count($durations) > 0) {
            $stats['avgDurationMs'] = array_sum($durations) / \count($durations);
        }

        return $stats;
    }

    public function cleanup(DateTimeInterface $before): int
    {
        $deleted = 0;

        foreach ($this->flowRuns as $id => $run) {
            if ($run->getStartedAt() < $before) {
                // Remove steps
                foreach ($run->getSteps() as $step) {
                    unset($this->flowSteps[$step->getId()]);
                }

                unset($this->flowRuns[$id]);
                ++$deleted;
            }
        }

        return $deleted;
    }

    /**
     * Clear all stored data (useful for testing).
     */
    public function clear(): void
    {
        $this->flowRuns = [];
        $this->flowSteps = [];
    }
}
