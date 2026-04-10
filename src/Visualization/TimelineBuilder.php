<?php

declare(strict_types=1);

namespace MichalKanak\MessageFlowVisualizerBundle\Visualization;

use DateTimeImmutable;
use MichalKanak\MessageFlowVisualizerBundle\Entity\FlowRun;
use MichalKanak\MessageFlowVisualizerBundle\Entity\FlowStep;

/**
 * Builds timeline data for temporal visualization.
 */
class TimelineBuilder
{
    /**
     * Build timeline data for temporal visualization.
     *
     * @return array{totalDuration: int, spans: array<int, array<string, mixed>>}
     */
    public function buildTimelineData(FlowRun $flowRun): array
    {
        $steps = $flowRun->getSteps();
        $startTime = $flowRun->getStartedAt();

        // Calculate total duration from steps if not set on flow run
        $totalDuration = $flowRun->getDurationMs();
        if (null === $totalDuration || 0 === $totalDuration) {
            $maxEndTime = 0;
            foreach ($steps as $step) {
                $offset = $this->calculateOffset($startTime, $step->getDispatchedAt());
                $stepDuration = $step->getTotalDurationMs() ?? $step->getProcessingDurationMs() ?? 0;
                $endTime = $offset + $stepDuration;
                if ($endTime > $maxEndTime) {
                    $maxEndTime = $endTime;
                }
            }
            $totalDuration = $maxEndTime;
        }

        $spans = [];

        foreach ($steps as $step) {
            $dispatchOffset = $this->calculateOffset($startTime, $step->getDispatchedAt());

            $spans[] = [
                'id' => $step->getId(),
                'label' => $this->shortenClassName($step->getMessageClass()),
                'fullLabel' => $step->getMessageClass(),
                'handler' => $step->getHandlerClass() ? $this->getLastPart($step->getHandlerClass()) : null,
                'startOffset' => $dispatchOffset,
                'queueWaitDuration' => $step->getQueueWaitDurationMs() ?? 0,
                'processingDuration' => $step->getProcessingDurationMs() ?? 0,
                'totalDuration' => $step->getTotalDurationMs() ?? 0,
                'status' => $step->getStatus(),
                'isAsync' => $step->isAsync(),
                'transport' => $step->getTransport(),
                'parentId' => $step->getParentStepId(),
                'color' => $this->getStatusColor($step->getStatus()),
                'queueColor' => '#ffc107', // Yellow for queue wait
                'exception' => $step->getExceptionMessage(),
            ];
        }

        // Sort by start offset for proper layering
        usort($spans, static fn ($a, $b) => $a['startOffset'] <=> $b['startOffset']);

        // Calculate depth for nested visualization
        $this->calculateDepths($spans);

        return [
            'totalDuration' => $totalDuration,
            'spans' => $spans,
        ];
    }

    /**
     * Calculate depth level for each span based on parent-child relationships.
     *
     * @param array<int, array<string, mixed>> &$spans
     */
    private function calculateDepths(array &$spans): void
    {
        $depthMap = [];

        foreach ($spans as $index => &$span) {
            $parentId = $span['parentId'];

            if (null === $parentId) {
                $span['depth'] = 0;
            } elseif (isset($depthMap[$parentId])) {
                $span['depth'] = $depthMap[$parentId] + 1;
            } else {
                $span['depth'] = 0;
            }

            $depthMap[$span['id']] = $span['depth'];
        }
    }

    private function calculateOffset(DateTimeImmutable $start, DateTimeImmutable $point): int
    {
        $diff = ($point->getTimestamp() - $start->getTimestamp()) * 1000;
        $microDiff = ((int) $point->format('u') - (int) $start->format('u')) / 1000;

        return max(0, (int) ($diff + $microDiff));
    }

    private function shortenClassName(string $className): string
    {
        $parts = explode('\\', $className);

        return end($parts);
    }

    private function getLastPart(string $fullName): string
    {
        // For handlers like App\MessageHandler\FooHandler::__invoke
        // Return just FooHandler::__invoke
        if (str_contains($fullName, '::')) {
            $parts = explode('::', $fullName);
            $class = $this->shortenClassName($parts[0]);

            return $class.'::'.$parts[1];
        }

        return $this->shortenClassName($fullName);
    }

    private function getStatusColor(string $status): string
    {
        return match ($status) {
            FlowStep::STATUS_HANDLED => '#28a745',
            FlowStep::STATUS_FAILED => '#dc3545',
            FlowStep::STATUS_RETRIED => '#fd7e14',
            FlowStep::STATUS_PENDING => '#6c757d',
            default => '#17a2b8',
        };
    }
}
