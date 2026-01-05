<?php

declare(strict_types=1);

namespace MichalKanak\MessageFlowVisualizerBundle\Storage;

use DateTimeInterface;
use MichalKanak\MessageFlowVisualizerBundle\Entity\FlowRun;
use MichalKanak\MessageFlowVisualizerBundle\Entity\FlowStep;

/**
 * Interface for flow data storage backends.
 */
interface StorageInterface
{
    /**
     * Save or update a flow run.
     */
    public function saveFlowRun(FlowRun $run): void;

    /**
     * Save or update a flow step.
     */
    public function saveFlowStep(FlowStep $step): void;

    /**
     * Find a flow run by its ID.
     */
    public function findFlowRun(string $id): ?FlowRun;

    /**
     * Find a flow run by its trace ID.
     */
    public function findFlowRunByTraceId(string $traceId): ?FlowRun;

    /**
     * Find all steps belonging to a flow run.
     *
     * @return FlowStep[]
     */
    public function findStepsByFlowRun(string $flowRunId): array;

    /**
     * Find recent flow runs.
     *
     * @return FlowRun[]
     *
     * @deprecated Use findRecentFlowRunsPaginated() for better pagination support
     */
    public function findRecentFlowRuns(int $limit = 50, ?string $status = null): array;

    /**
     * Find recent flow runs with pagination support.
     *
     * @return PaginatedResult<FlowRun>
     */
    public function findRecentFlowRunsPaginated(
        int $limit = 50,
        int $offset = 0,
        ?string $status = null,
    ): PaginatedResult;

    /**
     * Get statistics for a time range.
     *
     * @return array{
     *     totalFlows: int,
     *     completedFlows: int,
     *     failedFlows: int,
     *     avgDurationMs: float,
     *     messageClasses: array<string, int>
     * }
     */
    public function getStatistics(DateTimeInterface $from, DateTimeInterface $to): array;

    /**
     * Clean up old flow data.
     *
     * @return int Number of deleted flow runs
     */
    public function cleanup(DateTimeInterface $before): int;
}
