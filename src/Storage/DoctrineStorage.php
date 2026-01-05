<?php

declare(strict_types=1);

namespace MichalKanak\MessageFlowVisualizerBundle\Storage;

use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use MichalKanak\MessageFlowVisualizerBundle\Entity\FlowRun;
use MichalKanak\MessageFlowVisualizerBundle\Entity\FlowStep;

/**
 * Doctrine ORM storage implementation (optional).
 *
 * Note: This requires additional setup for Doctrine mappings.
 */
class DoctrineStorage implements StorageInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function saveFlowRun(FlowRun $run): void
    {
        // Check if entity exists in database (for merge in multi-process scenarios)
        $existing = $this->entityManager->find(FlowRun::class, $run->getId());

        if (null !== $existing && $existing !== $run) {
            // Merge step statuses from incoming run to existing entity
            $this->mergeFlowRunSteps($existing, $run);

            // Keep most complete flow status
            if ($this->getStatusPriority($run->getStatus()) > $this->getStatusPriority($existing->getStatus())) {
                // Update existing to match new status
                if (FlowRun::STATUS_COMPLETED === $run->getStatus()) {
                    $existing->markCompleted();
                } elseif (FlowRun::STATUS_FAILED === $run->getStatus()) {
                    $existing->markFailed();
                }
            }

            $this->entityManager->flush();
        } else {
            $this->entityManager->persist($run);
            $this->entityManager->flush();
        }
    }

    private function getStatusPriority(string $status): int
    {
        return match ($status) {
            FlowRun::STATUS_RUNNING => 1,
            FlowRun::STATUS_FAILED => 2,
            FlowRun::STATUS_COMPLETED => 3,
            default => 0,
        };
    }

    private function getStepStatusPriority(string $status): int
    {
        return match ($status) {
            FlowStep::STATUS_PENDING => 1,
            FlowStep::STATUS_HANDLED => 2,
            FlowStep::STATUS_FAILED => 3,
            default => 0,
        };
    }

    private function mergeFlowRunSteps(FlowRun $existing, FlowRun $incoming): void
    {
        $existingStepsById = [];
        foreach ($existing->getSteps() as $step) {
            $existingStepsById[$step->getId()] = $step;
        }

        foreach ($incoming->getSteps() as $incomingStep) {
            $stepId = $incomingStep->getId();

            if (!isset($existingStepsById[$stepId])) {
                // New step - add it
                $existing->addStep($incomingStep);
                $this->entityManager->persist($incomingStep);
            } else {
                // Existing step - merge if incoming has higher priority status
                $existingStep = $existingStepsById[$stepId];
                $incomingPriority = $this->getStepStatusPriority($incomingStep->getStatus());
                $existingPriority = $this->getStepStatusPriority($existingStep->getStatus());

                if ($incomingPriority > $existingPriority) {
                    // Copy relevant fields from incoming to existing
                    if (FlowStep::STATUS_HANDLED === $incomingStep->getStatus()) {
                        $existingStep->markHandled();
                    } elseif (FlowStep::STATUS_FAILED === $incomingStep->getStatus()) {
                        $exception = new Exception('Marked as failed');
                        $existingStep->markFailed($exception);
                    }

                    if (null !== $incomingStep->getHandlerClass()) {
                        $existingStep->setHandlerClass($incomingStep->getHandlerClass());
                    }
                }
            }
        }
    }

    public function saveFlowStep(FlowStep $step): void
    {
        $this->entityManager->persist($step);
        $this->entityManager->flush();
    }

    public function findFlowRun(string $id): ?FlowRun
    {
        return $this->entityManager->find(FlowRun::class, $id);
    }

    public function findFlowRunByTraceId(string $traceId): ?FlowRun
    {
        return $this->entityManager
            ->getRepository(FlowRun::class)
            ->findOneBy(['traceId' => $traceId]);
    }

    public function findStepsByFlowRun(string $flowRunId): array
    {
        return $this->entityManager
            ->getRepository(FlowStep::class)
            ->findBy(['flowRunId' => $flowRunId], ['dispatchedAt' => 'ASC']);
    }

    public function findRecentFlowRuns(int $limit = 50, ?string $status = null): array
    {
        $qb = $this->entityManager
            ->getRepository(FlowRun::class)
            ->createQueryBuilder('fr')
            ->orderBy('fr.startedAt', 'DESC')
            ->setMaxResults($limit);

        if (null !== $status) {
            $qb->where('fr.status = :status')
                ->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    public function findRecentFlowRunsPaginated(
        int $limit = 50,
        int $offset = 0,
        ?string $status = null,
    ): PaginatedResult {
        $countQb = $this->entityManager
            ->getRepository(FlowRun::class)
            ->createQueryBuilder('fr')
            ->select('COUNT(fr.id)');

        if (null !== $status) {
            $countQb->where('fr.status = :status')
                ->setParameter('status', $status);
        }

        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        $qb = $this->entityManager
            ->getRepository(FlowRun::class)
            ->createQueryBuilder('fr')
            ->orderBy('fr.startedAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        if (null !== $status) {
            $qb->where('fr.status = :status')
                ->setParameter('status', $status);
        }

        $items = $qb->getQuery()->getResult();

        return new PaginatedResult($items, $total, $limit, $offset);
    }

    public function getStatistics(DateTimeInterface $from, DateTimeInterface $to): array
    {
        $qb = $this->entityManager
            ->getRepository(FlowRun::class)
            ->createQueryBuilder('fr')
            ->select(
                'COUNT(fr.id) as totalFlows',
                'SUM(CASE WHEN fr.status = :completed THEN 1 ELSE 0 END) as completedFlows',
                'SUM(CASE WHEN fr.status = :failed THEN 1 ELSE 0 END) as failedFlows',
            )
            ->where('fr.startedAt >= :from')
            ->andWhere('fr.startedAt <= :to')
            ->setParameter('completed', FlowRun::STATUS_COMPLETED)
            ->setParameter('failed', FlowRun::STATUS_FAILED)
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        $result = $qb->getQuery()->getSingleResult();

        // Get message class stats
        $stepQb = $this->entityManager
            ->getRepository(FlowStep::class)
            ->createQueryBuilder('fs')
            ->select('fs.messageClass, COUNT(fs.id) as count')
            ->innerJoin('fs.flowRun', 'fr')
            ->where('fr.startedAt >= :from')
            ->andWhere('fr.startedAt <= :to')
            ->groupBy('fs.messageClass')
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        $messageClasses = [];
        foreach ($stepQb->getQuery()->getResult() as $row) {
            $messageClasses[$row['messageClass']] = (int) $row['count'];
        }

        return [
            'totalFlows' => (int) ($result['totalFlows'] ?? 0),
            'completedFlows' => (int) ($result['completedFlows'] ?? 0),
            'failedFlows' => (int) ($result['failedFlows'] ?? 0),
            'avgDurationMs' => 0.0, // Would require additional query
            'messageClasses' => $messageClasses,
        ];
    }

    public function cleanup(DateTimeInterface $before): int
    {
        $qb = $this->entityManager
            ->getRepository(FlowRun::class)
            ->createQueryBuilder('fr')
            ->delete()
            ->where('fr.startedAt < :before')
            ->setParameter('before', $before);

        return $qb->getQuery()->execute();
    }
}
