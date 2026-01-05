<?php

declare(strict_types=1);

namespace MichalKanak\MessageFlowVisualizerBundle\Visualization;

use MichalKanak\MessageFlowVisualizerBundle\Entity\FlowRun;
use MichalKanak\MessageFlowVisualizerBundle\Entity\FlowStep;

/**
 * Builds graph data structure for flow visualization.
 */
class FlowGraphBuilder
{
    /**
     * Build graph data for hierarchical layout visualization.
     *
     * @return array{nodes: array<int, array<string, mixed>>, links: array<int, array<string, mixed>>}
     */
    public function buildGraphData(FlowRun $flowRun): array
    {
        $steps = $flowRun->getSteps();

        $nodes = [];
        $links = [];
        $stepIndex = [];

        // Create nodes
        foreach ($steps as $index => $step) {
            $stepIndex[$step->getId()] = $index;

            $nodes[] = [
                'id' => $step->getId(),
                'index' => $index,
                'label' => $this->shortenClassName($step->getMessageClass()),
                'fullName' => $step->getMessageClass(),
                'handler' => $step->getHandlerClass() ? $this->shortenClassName($step->getHandlerClass()) : null,
                'handlerFull' => $step->getHandlerClass(),
                'transport' => $step->getTransport(),
                'isAsync' => $step->isAsync(),
                'status' => $step->getStatus(),
                'processingMs' => $step->getProcessingDurationMs(),
                'queueWaitMs' => $step->getQueueWaitDurationMs(),
                'totalMs' => $step->getTotalDurationMs(),
                'color' => $this->getStatusColor($step->getStatus()),
                'exception' => $step->getExceptionMessage(),
            ];
        }

        // Create links (edges)
        foreach ($steps as $step) {
            $parentId = $step->getParentStepId();
            if (null !== $parentId && isset($stepIndex[$parentId])) {
                $links[] = [
                    'source' => $stepIndex[$parentId],
                    'target' => $stepIndex[$step->getId()],
                    'sourceId' => $parentId,
                    'targetId' => $step->getId(),
                    'isAsync' => $step->isAsync(),
                    'transport' => $step->getTransport(),
                ];
            }
        }

        return [
            'nodes' => $nodes,
            'links' => $links,
        ];
    }

    /**
     * Build Mermaid diagram syntax (fallback for simple rendering).
     */
    public function buildMermaidDiagram(FlowRun $flowRun): string
    {
        $steps = $flowRun->getSteps();

        if (0 === \count($steps)) {
            return 'graph TD\n    empty[No steps recorded]';
        }

        $lines = ['graph TD'];
        $nodeIds = [];

        // Create node definitions
        foreach ($steps as $index => $step) {
            $nodeId = 'n'.$index;
            $nodeIds[$step->getId()] = $nodeId;

            $label = $this->shortenClassName($step->getMessageClass());
            $shape = $step->isAsync() ? "(($label))" : "[$label]";

            $lines[] = \sprintf('    %s%s', $nodeId, $shape);

            // Add styling based on status
            $style = $this->getMermaidStyle($step->getStatus());
            if (null !== $style) {
                $lines[] = \sprintf('    style %s %s', $nodeId, $style);
            }
        }

        // Create edges
        foreach ($steps as $step) {
            $parentId = $step->getParentStepId();
            if (null !== $parentId && isset($nodeIds[$parentId])) {
                $sourceNode = $nodeIds[$parentId];
                $targetNode = $nodeIds[$step->getId()];

                $edgeLabel = $step->isAsync() ? '|async|' : '';
                $edgeStyle = $step->isAsync() ? '-.->' : '-->';

                $lines[] = \sprintf('    %s %s%s %s', $sourceNode, $edgeStyle, $edgeLabel, $targetNode);
            }
        }

        return implode("\n", $lines);
    }

    private function shortenClassName(string $className): string
    {
        $parts = explode('\\', $className);

        return end($parts);
    }

    private function getStatusColor(string $status): string
    {
        return match ($status) {
            FlowStep::STATUS_HANDLED => '#28a745',   // Green
            FlowStep::STATUS_FAILED => '#dc3545',    // Red
            FlowStep::STATUS_RETRIED => '#fd7e14',   // Orange
            FlowStep::STATUS_PENDING => '#6c757d',   // Gray
            default => '#17a2b8',                     // Info blue
        };
    }

    private function getMermaidStyle(string $status): ?string
    {
        return match ($status) {
            FlowStep::STATUS_HANDLED => 'fill:#28a745,color:#fff',
            FlowStep::STATUS_FAILED => 'fill:#dc3545,color:#fff',
            FlowStep::STATUS_RETRIED => 'fill:#fd7e14,color:#fff',
            FlowStep::STATUS_PENDING => 'fill:#6c757d,color:#fff',
            default => null,
        };
    }
}
