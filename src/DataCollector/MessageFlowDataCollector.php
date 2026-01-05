<?php

declare(strict_types=1);

namespace MichalKanak\MessageFlowVisualizerBundle\DataCollector;

use DateTimeImmutable;
use MichalKanak\MessageFlowVisualizerBundle\Entity\FlowRun;
use MichalKanak\MessageFlowVisualizerBundle\Entity\FlowStep;
use MichalKanak\MessageFlowVisualizerBundle\Trace\TraceContext;
use MichalKanak\MessageFlowVisualizerBundle\Visualization\FlowGraphBuilder;
use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Data collector for Symfony Profiler integration.
 */
class MessageFlowDataCollector extends AbstractDataCollector
{
    /** @var FlowRun[] */
    private array $collectedFlows = [];

    public function __construct(
        private readonly TraceContext $traceContext,
        private readonly FlowGraphBuilder $graphBuilder,
    ) {
    }

    public function collect(Request $request, Response $response, ?Throwable $exception = null): void
    {
        $flowRun = $this->traceContext->getCurrentFlowRun();

        $this->data = [
            'flows' => [],
            'totalFlows' => 0,
            'totalSteps' => 0,
            'failedSteps' => 0,
            'asyncSteps' => 0,
            'totalDurationMs' => 0,
        ];

        // Collect from current context
        if (null !== $flowRun) {
            $this->addFlow($flowRun);
        }

        // Also add any collected flows during request
        foreach ($this->collectedFlows as $flow) {
            if (null === $flowRun || $flow->getId() !== $flowRun->getId()) {
                $this->addFlow($flow);
            }
        }
    }

    /**
     * Add a flow to collect (called from middleware/subscriber).
     */
    public function addCollectedFlow(FlowRun $flowRun): void
    {
        $this->collectedFlows[$flowRun->getId()] = $flowRun;
    }

    private function addFlow(FlowRun $flowRun): void
    {
        $steps = $flowRun->getSteps();

        $flowData = [
            'id' => $flowRun->getId(),
            'traceId' => $flowRun->getTraceId(),
            'status' => $flowRun->getStatus(),
            'startedAt' => $flowRun->getStartedAt()->format('H:i:s.u'),
            'finishedAt' => $flowRun->getFinishedAt()?->format('H:i:s.u'),
            'durationMs' => $flowRun->getDurationMs(),
            'initiator' => $flowRun->getInitiator(),
            'steps' => [],
            'graphData' => $this->graphBuilder->buildGraphData($flowRun),
            'timelineData' => $this->buildTimelineData($flowRun),
        ];

        foreach ($steps as $step) {
            $flowData['steps'][] = [
                'id' => $step->getId(),
                'messageClass' => $step->getMessageClass(),
                'messageClassShort' => $this->shortenClassName($step->getMessageClass()),
                'handlerClass' => $step->getHandlerClass(),
                'handlerClassShort' => $step->getHandlerClass() ? $this->shortenClassName($step->getHandlerClass()) : null,
                'transport' => $step->getTransport(),
                'isAsync' => $step->isAsync(),
                'status' => $step->getStatus(),
                'processingDurationMs' => $step->getProcessingDurationMs(),
                'queueWaitDurationMs' => $step->getQueueWaitDurationMs(),
                'totalDurationMs' => $step->getTotalDurationMs(),
                'dispatchedAt' => $step->getDispatchedAt()->format('H:i:s.u'),
                'handledAt' => $step->getHandledAt()?->format('H:i:s.u'),
                'exceptionClass' => $step->getExceptionClass(),
                'exceptionMessage' => $step->getExceptionMessage(),
                'parentStepId' => $step->getParentStepId(),
            ];

            ++$this->data['totalSteps'];

            if (FlowStep::STATUS_FAILED === $step->getStatus()) {
                ++$this->data['failedSteps'];
            }

            if ($step->isAsync()) {
                ++$this->data['asyncSteps'];
            }
        }

        $this->data['flows'][] = $flowData;
        ++$this->data['totalFlows'];

        if (null !== $flowRun->getDurationMs()) {
            $this->data['totalDurationMs'] += $flowRun->getDurationMs();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTimelineData(FlowRun $flowRun): array
    {
        $steps = $flowRun->getSteps();
        $startTime = $flowRun->getStartedAt();

        $spans = [];
        foreach ($steps as $step) {
            $dispatchOffset = $this->calculateOffset($startTime, $step->getDispatchedAt());
            $duration = $step->getTotalDurationMs() ?? 0;

            $spans[] = [
                'id' => $step->getId(),
                'label' => $this->shortenClassName($step->getMessageClass()),
                'startOffset' => $dispatchOffset,
                'duration' => $duration,
                'queueWait' => $step->getQueueWaitDurationMs() ?? 0,
                'processing' => $step->getProcessingDurationMs() ?? 0,
                'status' => $step->getStatus(),
                'isAsync' => $step->isAsync(),
                'transport' => $step->getTransport(),
                'parentId' => $step->getParentStepId(),
            ];
        }

        return [
            'totalDuration' => $flowRun->getDurationMs() ?? 0,
            'spans' => $spans,
        ];
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

    public static function getTemplate(): ?string
    {
        return '@MessageFlowVisualizer/data_collector/message_flow.html.twig';
    }

    public function getName(): string
    {
        return 'message_flow';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFlows(): array
    {
        return $this->data['flows'] ?? [];
    }

    public function getTotalFlows(): int
    {
        return $this->data['totalFlows'] ?? 0;
    }

    public function getTotalSteps(): int
    {
        return $this->data['totalSteps'] ?? 0;
    }

    public function getFailedSteps(): int
    {
        return $this->data['failedSteps'] ?? 0;
    }

    public function getAsyncSteps(): int
    {
        return $this->data['asyncSteps'] ?? 0;
    }

    public function getTotalDurationMs(): int
    {
        return $this->data['totalDurationMs'] ?? 0;
    }

    public function reset(): void
    {
        $this->data = [];
        $this->collectedFlows = [];
    }
}
