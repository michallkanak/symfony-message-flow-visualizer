<?php

declare(strict_types=1);

namespace MichalKanak\MessageFlowVisualizerBundle\Controller;

use DateTimeImmutable;
use Exception;
use MichalKanak\MessageFlowVisualizerBundle\Storage\StorageInterface;
use MichalKanak\MessageFlowVisualizerBundle\Visualization\FlowGraphBuilder;
use MichalKanak\MessageFlowVisualizerBundle\Visualization\TimelineBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Optional dashboard controller.
 *
 * Routes are NOT registered by default.
 * User must manually add to their routing configuration:
 *
 * # config/routes/message_flow.yaml
 * message_flow:
 *     resource: '@MessageFlowVisualizerBundle/src/Controller/'
 *     type: attribute
 */
#[Route('/message-flow', name: 'message_flow_')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly FlowGraphBuilder $graphBuilder,
        private readonly TimelineBuilder $timelineBuilder,
        private readonly int $slowThresholdMs = 500,
    ) {
    }

    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $limit = $request->query->getInt('limit', 20);
        $page = max(1, $request->query->getInt('page', 1));
        $offset = ($page - 1) * $limit;
        $status = $request->query->get('status', false);
        $slowOnly = $request->query->getBoolean('slow_only', false);
        $search = $request->query->get('search', null) ?? null;

        $statusFilter = \is_string($status) && '' !== $status ? $status : null;
        $result = $this->storage->findRecentFlowRunsPaginated(
            $limit * 3,
            0,
            $statusFilter,
        );

        $flows = $result->items;
        $filteredFlows = [];
        foreach ($flows as $flow) {
            if ($slowOnly) {
                $duration = $flow->getDurationMs();
                if (null === $duration || $duration <= $this->slowThresholdMs) {
                    continue;
                }
            }

            if ('' != $search) {
                $matchFound = false;
                if (str_contains(strtolower($flow->getInitiator() ?? ''), strtolower($search))) {
                    $matchFound = true;
                }
                // Search in message classes
                if (!$matchFound) {
                    foreach ($flow->getSteps() as $step) {
                        if (str_contains(strtolower($step->getMessageClass()), strtolower($search))) {
                            $matchFound = true;
                            break;
                        }
                    }
                }
                if (!$matchFound) {
                    continue;
                }
            }

            $filteredFlows[] = $flow;
            if (\count($filteredFlows) >= $limit) {
                break;
            }
        }

        return $this->render('@MessageFlowVisualizer/dashboard/index.html.twig', [
            'flows' => $filteredFlows,
            'status' => $status,
            'limit' => $limit,
            'page' => $page,
            'totalFlows' => $result->total,
            'hasMore' => \count($filteredFlows) >= $limit,
            'slow_only' => $slowOnly,
            'search' => $search,
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(string $id): Response
    {
        $flowRun = $this->storage->findFlowRun($id);

        if (null === $flowRun) {
            throw $this->createNotFoundException('Flow not found');
        }

        $graphData = $this->graphBuilder->buildGraphData($flowRun);
        $timelineData = $this->timelineBuilder->buildTimelineData($flowRun);
        $mermaidDiagram = $this->graphBuilder->buildMermaidDiagram($flowRun);

        return $this->render('@MessageFlowVisualizer/dashboard/show.html.twig', [
            'flow' => $flowRun,
            'graphData' => $graphData,
            'timelineData' => $timelineData,
            'mermaidDiagram' => $mermaidDiagram,
        ]);
    }

    #[Route('/stats', name: 'stats', methods: ['GET'], priority: 10)]
    public function stats(Request $request): Response
    {
        $fromStr = $request->query->get('from', '-1 day');
        $toStr = $request->query->get('to', 'now');

        try {
            $from = new DateTimeImmutable($fromStr);
            $to = new DateTimeImmutable($toStr);
        } catch (Exception) {
            $from = new DateTimeImmutable('-1 day');
            $to = new DateTimeImmutable();
        }

        $stats = $this->storage->getStatistics($from, $to);

        return $this->render('@MessageFlowVisualizer/dashboard/stats.html.twig', [
            'stats' => $stats,
            'from' => $from,
            'to' => $to,
        ]);
    }
}
