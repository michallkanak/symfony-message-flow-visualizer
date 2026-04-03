<?php

declare(strict_types=1);

namespace MichalKanak\MessageFlowVisualizerBundle\Storage;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use MichalKanak\MessageFlowVisualizerBundle\Entity\FlowRun;
use MichalKanak\MessageFlowVisualizerBundle\Entity\FlowStep;

/**
 * Filesystem-based storage implementation (default).
 *
 * Stores flow data as JSON files organized by date.
 */
class FilesystemStorage implements StorageInterface
{
    private string $storagePath;

    public function __construct(string $storagePath)
    {
        $this->storagePath = rtrim($storagePath, '/\\');
    }

    public function saveFlowRun(FlowRun $run): void
    {
        $path = $this->getFlowRunPath($run->getId(), $run->getStartedAt());
        $this->ensureDirectory(\dirname($path));

        // Merge strategy: load existing data and merge steps to preserve most complete status
        $data = $run->toArray();

        if (file_exists($path)) {
            $existingContent = file_get_contents($path);
            if (false !== $existingContent && '' !== $existingContent) {
                try {
                    /** @var array<string, mixed> $existingData */
                    $existingData = json_decode($existingContent, true, 512, \JSON_THROW_ON_ERROR);
                    $data = $this->mergeFlowRunData($existingData, $data);
                } catch (JsonException $e) {
                    // Corrupted file, overwrite with new data
                }
            }
        }

        file_put_contents($path, json_encode($data, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));

        // Update index with merged data
        $mergedRun = FlowRun::fromArray($data);
        $this->updateIndex($mergedRun);
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
        // Keep the most complete flow status (completed > failed > running)
        $statusPriority = [
            FlowRun::STATUS_RUNNING => 1,
            FlowRun::STATUS_FAILED => 2,
            FlowRun::STATUS_COMPLETED => 3,
        ];

        $existingPriority = $statusPriority[$existing['status']] ?? 0;
        $newPriority = $statusPriority[$new['status']] ?? 0;

        $result = $new;

        // Keep completed/failed status and finishedAt if existing has it
        if ($existingPriority > $newPriority) {
            $result['status'] = $existing['status'];
            $result['finishedAt'] = $existing['finishedAt'] ?? $new['finishedAt'];
        }

        // Merge steps - create map by step ID
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

        // Merge all steps, keeping most complete status
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
                // Both exist - merge, keeping most complete data
                $mergedStep = $this->mergeStepData($existingStep, $newStep, $stepStatusPriority);
                $mergedSteps[] = $mergedStep;
            }
        }

        $result['steps'] = $mergedSteps;

        return $result;
    }

    /**
     * Merge two step data arrays, keeping the most complete information.
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

        // Start with the step that has higher priority status
        if ($existingPriority > $newPriority) {
            $result = $existing;
            // Add any new fields from $new that are set and missing in $existing
            foreach (['handlerClass', 'handledAt', 'receivedAt', 'processingDurationMs', 'queueWaitDurationMs', 'totalDurationMs'] as $field) {
                if (empty($result[$field]) && !empty($new[$field])) {
                    $result[$field] = $new[$field];
                }
            }
        } else {
            $result = $new;
            // Add any fields from $existing that are set and missing in $new
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

        // Find and update existing step or add new one
        $steps = $flowRun->getSteps();
        $found = false;

        foreach ($steps as $existingStep) {
            if ($existingStep->getId() === $step->getId()) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            $flowRun->addStep($step);
        }

        $this->saveFlowRun($flowRun);
    }

    public function findFlowRun(string $id): ?FlowRun
    {
        // Search in index first
        $index = $this->loadIndex();

        if (isset($index['runs'][$id])) {
            $path = $index['runs'][$id]['path'];
            if (file_exists($path)) {
                return $this->loadFlowRunFromFile($path);
            }
        }

        // Fallback: search in date directories
        $runsPath = $this->storagePath.'/runs';
        if (!is_dir($runsPath)) {
            return null;
        }

        $dateDirs = scandir($runsPath);
        if (false === $dateDirs) {
            return null;
        }

        foreach (array_reverse($dateDirs) as $dateDir) {
            if ('.' === $dateDir || '..' === $dateDir) {
                continue;
            }

            $filePath = \sprintf('%s/%s/%s.json', $runsPath, $dateDir, $id);
            if (file_exists($filePath)) {
                return $this->loadFlowRunFromFile($filePath);
            }
        }

        return null;
    }

    public function findFlowRunByTraceId(string $traceId): ?FlowRun
    {
        $index = $this->loadIndex();

        foreach ($index['runs'] ?? [] as $runData) {
            if (($runData['traceId'] ?? null) === $traceId) {
                $path = $runData['path'];
                if (file_exists($path)) {
                    return $this->loadFlowRunFromFile($path);
                }
            }
        }

        return null;
    }

    public function findStepsByFlowRun(string $flowRunId): array
    {
        $flowRun = $this->findFlowRun($flowRunId);

        return $flowRun?->getSteps() ?? [];
    }

    public function findRecentFlowRuns(int $limit = 50, ?string $status = null): array
    {
        $index = $this->loadIndex();
        $runs = [];

        $indexRuns = $index['runs'] ?? [];

        // Sort by startedAt descending
        uasort($indexRuns, function ($a, $b) {
            return ($b['startedAt'] ?? '') <=> ($a['startedAt'] ?? '');
        });

        foreach ($indexRuns as $runData) {
            if (null !== $status && ($runData['status'] ?? null) !== $status) {
                continue;
            }

            $path = $runData['path'] ?? null;
            if (null !== $path && file_exists($path)) {
                $run = $this->loadFlowRunFromFile($path);
                if (null !== $run) {
                    $runs[] = $run;
                }
            }

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
        $index = $this->loadIndex();
        $indexRuns = $index['runs'] ?? [];

        if (null !== $status) {
            $indexRuns = array_filter(
                $indexRuns,
                fn (array $runData) => ($runData['status'] ?? null) === $status,
            );
        }

        uasort($indexRuns, function ($a, $b) {
            return ($b['startedAt'] ?? '') <=> ($a['startedAt'] ?? '');
        });

        $total = \count($indexRuns);
        $sliced = \array_slice(array_values($indexRuns), $offset, $limit);

        $runs = [];
        foreach ($sliced as $runData) {
            $path = $runData['path'] ?? null;
            if (null !== $path && file_exists($path)) {
                $run = $this->loadFlowRunFromFile($path);
                if (null !== $run) {
                    $runs[] = $run;
                }
            }
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

        $durations = [];
        $runs = $this->findRecentFlowRuns(1000);

        foreach ($runs as $run) {
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
        $runsPath = $this->storagePath.'/runs';

        if (!is_dir($runsPath)) {
            return 0;
        }

        $dateDirs = scandir($runsPath);
        if (false === $dateDirs) {
            return 0;
        }

        $beforeDate = $before->format('Y-m-d');

        foreach ($dateDirs as $dateDir) {
            if ('.' === $dateDir || '..' === $dateDir) {
                continue;
            }

            if ($dateDir < $beforeDate) {
                $dirPath = $runsPath.'/'.$dateDir;
                $files = glob($dirPath.'/*.json');

                if (false !== $files) {
                    foreach ($files as $file) {
                        unlink($file);
                        ++$deleted;
                    }
                }

                @rmdir($dirPath);
            }
        }

        // Update index
        $this->rebuildIndex();

        return $deleted;
    }

    private function getFlowRunPath(string $id, DateTimeImmutable $startedAt): string
    {
        $date = $startedAt->format('Y-m-d');

        return \sprintf('%s/runs/%s/%s.json', $this->storagePath, $date, $id);
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0o755, true);
        }
    }

    private function loadFlowRunFromFile(string $path): ?FlowRun
    {
        $content = file_get_contents($path);
        if (false === $content) {
            return null;
        }

        try {
            $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

            return FlowRun::fromArray($data);
        } catch (JsonException) {
            return null;
        }
    }

    private function getIndexPath(): string
    {
        return $this->storagePath.'/index.json';
    }

    /**
     * @return array<string, mixed>
     */
    private function loadIndex(): array
    {
        $indexPath = $this->getIndexPath();

        if (!file_exists($indexPath)) {
            return ['runs' => []];
        }

        $content = file_get_contents($indexPath);
        if (false === $content) {
            return ['runs' => []];
        }

        try {
            return json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['runs' => []];
        }
    }

    private function updateIndex(FlowRun $run): void
    {
        $index = $this->loadIndex();

        $index['runs'][$run->getId()] = [
            'id' => $run->getId(),
            'traceId' => $run->getTraceId(),
            'startedAt' => $run->getStartedAt()->format('c'),
            'status' => $run->getStatus(),
            'path' => $this->getFlowRunPath($run->getId(), $run->getStartedAt()),
        ];

        // Keep only last 1000 entries in index
        if (\count($index['runs']) > 1000) {
            uasort($index['runs'], fn ($a, $b) => ($b['startedAt'] ?? '') <=> ($a['startedAt'] ?? ''));
            $index['runs'] = \array_slice($index['runs'], 0, 1000, true);
        }

        $this->ensureDirectory(\dirname($this->getIndexPath()));
        file_put_contents($this->getIndexPath(), json_encode($index, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
    }

    private function rebuildIndex(): void
    {
        $index = ['runs' => []];
        $runsPath = $this->storagePath.'/runs';

        if (!is_dir($runsPath)) {
            return;
        }

        $dateDirs = scandir($runsPath);
        if (false === $dateDirs) {
            return;
        }

        foreach (array_reverse($dateDirs) as $dateDir) {
            if ('.' === $dateDir || '..' === $dateDir) {
                continue;
            }

            $files = glob($runsPath.'/'.$dateDir.'/*.json');
            if (false === $files) {
                continue;
            }

            foreach ($files as $file) {
                $run = $this->loadFlowRunFromFile($file);
                if (null !== $run) {
                    $index['runs'][$run->getId()] = [
                        'id' => $run->getId(),
                        'traceId' => $run->getTraceId(),
                        'startedAt' => $run->getStartedAt()->format('c'),
                        'status' => $run->getStatus(),
                        'path' => $file,
                    ];
                }

                if (\count($index['runs']) >= 1000) {
                    break 2;
                }
            }
        }

        $this->ensureDirectory(\dirname($this->getIndexPath()));
        file_put_contents($this->getIndexPath(), json_encode($index, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
    }
}
