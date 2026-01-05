<?php

declare(strict_types=1);

namespace MichalKanak\MessageFlowVisualizerBundle\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Represents a complete message flow run (a tree of message steps).
 */
#[ORM\Entity]
#[ORM\Table(name: 'mfv_flow_run')]
#[ORM\Index(columns: ['trace_id'], name: 'idx_mfv_flow_run_trace_id')]
#[ORM\Index(columns: ['started_at'], name: 'idx_mfv_flow_run_started_at')]
#[ORM\Index(columns: ['status'], name: 'idx_mfv_flow_run_status')]
class FlowRun
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    private string $id;

    #[ORM\Column(name: 'trace_id', type: Types::STRING, length: 36)]
    private string $traceId;

    #[ORM\Column(name: 'started_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'finished_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $initiator;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $metadata = [];

    /** @var Collection<int, FlowStep> */
    #[ORM\OneToMany(targetEntity: FlowStep::class, mappedBy: 'flowRun', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['dispatchedAt' => 'ASC'])]
    private Collection $steps;

    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public function __construct(
        ?string $id = null,
        ?string $traceId = null,
        ?string $initiator = null,
    ) {
        $this->id = $id ?? Uuid::v4()->toRfc4122();
        $this->traceId = $traceId ?? Uuid::v4()->toRfc4122();
        $this->startedAt = new DateTimeImmutable();
        $this->status = self::STATUS_RUNNING;
        $this->initiator = $initiator;
        $this->steps = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTraceId(): string
    {
        return $this->traceId;
    }

    public function getStartedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getInitiator(): ?string
    {
        return $this->initiator;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return FlowStep[]
     */
    public function getSteps(): array
    {
        return $this->steps->toArray();
    }

    public function addStep(FlowStep $step): self
    {
        // Prevent duplicate steps
        foreach ($this->steps as $existingStep) {
            if ($existingStep->getId() === $step->getId()) {
                return $this;
            }
        }

        $step->setFlowRun($this);
        $this->steps->add($step);

        return $this;
    }

    public function markCompleted(): self
    {
        $this->status = self::STATUS_COMPLETED;
        $this->finishedAt = new DateTimeImmutable();

        return $this;
    }

    public function markFailed(): self
    {
        $this->status = self::STATUS_FAILED;
        $this->finishedAt = new DateTimeImmutable();

        return $this;
    }

    public function setMetadata(string $key, mixed $value): self
    {
        $this->metadata[$key] = $value;

        return $this;
    }

    public function getDurationMs(): ?int
    {
        // If flow has finished, use finishedAt
        if (null !== $this->finishedAt) {
            return (int) (($this->finishedAt->getTimestamp() - $this->startedAt->getTimestamp()) * 1000
                + ($this->finishedAt->format('u') - $this->startedAt->format('u')) / 1000);
        }

        // Otherwise calculate from steps (for async flows that may not have finishedAt)
        if ($this->steps->isEmpty()) {
            return null;
        }

        $maxDuration = 0;
        $startTimestamp = $this->startedAt->getTimestamp() * 1000
            + (int) $this->startedAt->format('u') / 1000;

        foreach ($this->steps as $step) {
            $stepEndMs = 0;
            $dispatchedAt = $step->getDispatchedAt();
            $stepStartMs = $dispatchedAt->getTimestamp() * 1000
                + (int) $dispatchedAt->format('u') / 1000;
            $stepDuration = $step->getTotalDurationMs() ?? $step->getProcessingDurationMs() ?? 0;
            $stepEndMs = ($stepStartMs - $startTimestamp) + $stepDuration;
            if ($stepEndMs > $maxDuration) {
                $maxDuration = $stepEndMs;
            }
        }

        return $maxDuration > 0 ? (int) $maxDuration : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'traceId' => $this->traceId,
            'startedAt' => $this->startedAt->format('c'),
            'finishedAt' => $this->finishedAt?->format('c'),
            'status' => $this->status,
            'initiator' => $this->initiator,
            'metadata' => $this->metadata,
            'durationMs' => $this->getDurationMs(),
            'steps' => array_map(fn (FlowStep $step) => $step->toArray(), $this->getSteps()),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $run = new self();
        $run->id = $data['id'];
        $run->traceId = $data['traceId'];
        $run->startedAt = new DateTimeImmutable($data['startedAt']);
        $run->finishedAt = isset($data['finishedAt']) ? new DateTimeImmutable($data['finishedAt']) : null;
        $run->status = $data['status'];
        $run->initiator = $data['initiator'] ?? null;
        $run->metadata = $data['metadata'] ?? [];

        if (isset($data['steps']) && \is_array($data['steps'])) {
            foreach ($data['steps'] as $stepData) {
                $step = FlowStep::fromArray($stepData, $run);
                $run->steps->add($step);
            }
        }

        return $run;
    }
}
