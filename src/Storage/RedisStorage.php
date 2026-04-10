<?php

declare(strict_types=1);

namespace MichalKanak\MessageFlowVisualizerBundle\Storage;

use DateTimeInterface;
use JsonException;
use MichalKanak\MessageFlowVisualizerBundle\Entity\FlowRun;
use MichalKanak\MessageFlowVisualizerBundle\Entity\FlowStep;
use Predis\ClientInterface;

/**
 * Redis storage implementation using Predis library.
 *
 * Provides fast, ephemeral storage suitable for high-throughput environments.
 * Requires predis/predis package.
 */
class RedisStorage implements StorageInterface
{
    private const KEY_PREFIX = 'mfv:';
    private const RUNS_KEY = self::KEY_PREFIX.'runs';
    private const RUN_KEY_PREFIX = self::KEY_PREFIX.'run:';
    private const TRACE_INDEX_KEY = self::KEY_PREFIX.'trace_index';

    private ClientInterface $redis;
    private int $ttl = 604800; // 7 days default

    public function __construct(ClientInterface $redis)
    {
        $this->redis = $redis;
    }

    /**
     * Set TTL for stored data.
     */
    public function setTtl(int $ttl): void
    {
        $this->ttl = $ttl;
    }

    public function saveFlowRun(FlowRun $run): void
    {
        $key = self::RUN_KEY_PREFIX.$run->getId();

        // Merge strategy: load existing data and merge steps
        $data = $run->toArray();

        $existingData = $this->redis->get($key);
        if (null !== $existingData && '' !== $existingData) {
            try {
                /** @var array<string, mixed> $existing */
                $existing = json_decode($existingData, true, 512, \JSON_THROW_ON_ERROR);
                $data = $this->mergeFlowRunData($existing, $data);
            } catch (JsonException) {
                // Corrupted data, overwrite
            }
        }

        $encodedData = json_encode($data, \JSON_THROW_ON_ERROR);
        $this->redis->setex($key, $this->ttl, $encodedData);

        // Add to runs sorted set (score = timestamp)
        $this->redis->zadd(self::RUNS_KEY, [$run->getId() => $run->getStartedAt()->getTimestamp()]);

        // Add to trace index
        $this->redis->hset(self::TRACE_INDEX_KEY, $run->getTraceId(), $run->getId());
    }

    /**
     * Merge two FlowRun data arrays, keeping the most complete information.
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $new
     *
     * @return array<string, mixed>
     */
    private function mergeFlowRunData(array $existing, array $new): array
    {
        $statusPriority = [
            FlowRun::STATUS_RUNNING => 1,
            FlowRun::STATUS_FAILED => 2,
            FlowRun::STATUS_COMPLETED => 3,
        ];

        $existingPriority = $statusPriority[$existing['status']] ?? 0;
        $newPriority = $statusPriority[$new['status']] ?? 0;

        $result = $new;

        if ($existingPriority > $newPriority) {
            $result['status'] = $existing['status'];
            $result['finishedAt'] = $existing['finishedAt'] ?? $new['finishedAt'];
        }

        // Merge steps by ID
        $existingSteps = [];
        foreach (($existing['steps'] ?? []) as $step) {
            if (isset($step['id'])) {
                $existingSteps[$step['id']] = $step;
            }
        }

        $newSteps = [];
        foreach (($new['steps'] ?? []) as $step) {
            if (isset($step['id'])) {
                $newSteps[$step['id']] = $step;
            }
        }

        $mergedSteps = [];
        $allStepIds = array_unique(array_merge(array_keys($existingSteps), array_keys($newSteps)));

        $stepStatusPriority = [
            FlowStep::STATUS_PENDING => 1,
            FlowStep::STATUS_HANDLED => 2,
            FlowStep::STATUS_FAILED => 3,
        ];

        foreach ($allStepIds as $stepId) {
            $existingStep = $existingSteps[$stepId] ?? null;
            $newStep = $newSteps[$stepId] ?? null;

            if (null === $existingStep) {
                $mergedSteps[] = $newStep;
            } elseif (null === $newStep) {
                $mergedSteps[] = $existingStep;
            } else {
                $mergedSteps[] = $this->mergeStepData($existingStep, $newStep, $stepStatusPriority);
            }
        }

        $result['steps'] = $mergedSteps;

        return $result;
    }

    /**
     * Merge two step data arrays.
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $new
     * @param array<string, int>   $statusPriority
     *
     * @return array<string, mixed>
     */
    private function mergeStepData(array $existing, array $new, array $statusPriority): array
    {
        $existingPriority = $statusPriority[$existing['status']] ?? 0;
        $newPriority = $statusPriority[$new['status']] ?? 0;

        if ($existingPriority > $newPriority) {
            $result = $existing;
            foreach (['handlerClass', 'handledAt', 'receivedAt', 'processingDurationMs', 'queueWaitDurationMs', 'totalDurationMs'] as $field) {
                if (empty($result[$field]) && !empty($new[$field])) {
                    $result[$field] = $new[$field];
                }
            }
        } else {
            $result = $new;
            foreach (['handlerClass', 'handledAt', 'receivedAt', 'processingDurationMs', 'queueWaitDurationMs', 'totalDurationMs'] as $field) {
                if (empty($result[$field]) && !empty($existing[$field])) {
                    $result[$field] = $existing[$field];
                }
            }
        }

        return $result;
    }

    public function saveFlowStep(FlowStep $step): void
    {
        $flowRunId = $step->getFlowRunId();
        if (null === $flowRunId) {
            return;
        }

        $flowRun = $this->findFlowRun($flowRunId);
        if (null === $flowRun) {
            return;
        }

        // Check if step exists
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

        $this->saveFlowRun($flowRun);
    }

    public function findFlowRun(string $id): ?FlowRun
    {
        $key = self::RUN_KEY_PREFIX.$id;
        $data = $this->redis->get($key);

        if (null === $data || '' === $data) {
            return null;
        }

        try {
            /** @var array<string, mixed> $array */
            $array = json_decode($data, true, 512, \JSON_THROW_ON_ERROR);

            return FlowRun::fromArray($array);
        } catch (JsonException) {
            return null;
        }
    }

    public function findFlowRunByTraceId(string $traceId): ?FlowRun
    {
        $runId = $this->redis->hget(self::TRACE_INDEX_KEY, $traceId);

        if (null === $runId || '' === $runId) {
            return null;
        }

        return $this->findFlowRun($runId);
    }

    public function findStepsByFlowRun(string $flowRunId): array
    {
        $flowRun = $this->findFlowRun($flowRunId);

        return $flowRun?->getSteps() ?? [];
    }

    public function findRecentFlowRuns(int $limit = 50, ?string $status = null): array
    {
        // Get recent run IDs from sorted set (reverse order)
        $runIds = $this->redis->zrevrange(self::RUNS_KEY, 0, $limit * 2 - 1);

        if (0 === \count($runIds)) {
            return [];
        }

        $runs = [];
        foreach ($runIds as $runId) {
            $run = $this->findFlowRun((string) $runId);

            if (null === $run) {
                continue;
            }

            if (null !== $status && $run->getStatus() !== $status) {
                continue;
            }

            $runs[] = $run;

            if (\count($runs) >= $limit) {
                break;
            }
        }

        return $runs;
    }

    public function findRecentFlowRunsPaginated(
        int $limit = 50,
        int $offset = 0,
        ?string $status = null,
    ): PaginatedResult {
        $total = (int) $this->redis->zcard(self::RUNS_KEY);

        $fetchLimit = null !== $status ? $limit * 3 : $limit;
        $runIds = $this->redis->zrevrange(self::RUNS_KEY, $offset, $offset + $fetchLimit - 1);

        $runs = [];
        $skipped = 0;

        foreach ($runIds as $runId) {
            $run = $this->findFlowRun((string) $runId);

            if (null === $run) {
                continue;
            }

            if (null !== $status && $run->getStatus() !== $status) {
                ++$skipped;
                continue;
            }

            $runs[] = $run;

            if (\count($runs) >= $limit) {
                break;
            }
        }

        if (null !== $status) {
            $total = \count($runs) + $skipped;
        }

        return new PaginatedResult($runs, $total, $limit, $offset);
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

        $runIds = $this->redis->zrangebyscore(
            self::RUNS_KEY,
            (string) $from->getTimestamp(),
            (string) $to->getTimestamp(),
        );

        if (0 === \count($runIds)) {
            return $stats;
        }

        $durations = [];

        foreach ($runIds as $runId) {
            $run = $this->findFlowRun((string) $runId);
            if (null === $run) {
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
        $runIds = $this->redis->zrangebyscore(
            self::RUNS_KEY,
            '-inf',
            (string) $before->getTimestamp(),
        );

        if (0 === \count($runIds)) {
            return 0;
        }

        $deleted = 0;
        foreach ($runIds as $runId) {
            $run = $this->findFlowRun((string) $runId);
            if (null !== $run) {
                // Remove from trace index
                $this->redis->hdel(self::TRACE_INDEX_KEY, [$run->getTraceId()]);
            }

            // Remove run data
            $this->redis->del([self::RUN_KEY_PREFIX.$runId]);

            // Remove from sorted set
            $this->redis->zrem(self::RUNS_KEY, $runId);

            ++$deleted;
        }

        return $deleted;
    }
}
