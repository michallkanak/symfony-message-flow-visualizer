<?php

declare(strict_types=1);

namespace MichalKanak\MessageFlowVisualizerBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Represents a single step (message dispatch/handling) in a flow.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mfv_flow_step')]
#[ORM\Index(columns: ['flow_run_id'], name: 'idx_mfv_flow_step_flow_run_id')]
#[ORM\Index(columns: ['message_class'], name: 'idx_mfv_flow_step_message_class')]
#[ORM\Index(columns: ['status'], name: 'idx_mfv_flow_step_status')]
#[ORM\Index(columns: ['dispatched_at'], name: 'idx_mfv_flow_step_dispatched_at')]
class FlowStep
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: FlowRun::class, inversedBy: 'steps')]
    #[ORM\JoinColumn(name: 'flow_run_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ?FlowRun $flowRun = null;

    #[ORM\Column(name: 'flow_run_id', type: Types::STRING, length: 36, nullable: true, insertable: false, updatable: false)]
    private ?string $flowRunId = null;

    #[ORM\Column(name: 'parent_step_id', type: Types::STRING, length: 36, nullable: true)]
    private ?string $parentStepId = null;

    #[ORM\Column(name: 'message_class', type: Types::STRING, length: 255)]
    private string $messageClass;

    #[ORM\Column(name: 'message_id', type: Types::STRING, length: 255, nullable: true)]
    private ?string $messageId = null;

    #[ORM\Column(name: 'handler_class', type: Types::STRING, length: 255, nullable: true)]
    private ?string $handlerClass = null;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $transport;

    #[ORM\Column(name: 'is_async', type: Types::BOOLEAN)]
    private bool $isAsync;

    // Timestamps
    #[ORM\Column(name: 'dispatched_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $dispatchedAt;

    #[ORM\Column(name: 'received_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $receivedAt = null;

    #[ORM\Column(name: 'handled_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $handledAt = null;

    // Dual timing metrics
    #[ORM\Column(name: 'processing_duration_ms', type: Types::INTEGER, nullable: true)]
    private ?int $processingDurationMs = null;

    #[ORM\Column(name: 'queue_wait_duration_ms', type: Types::INTEGER, nullable: true)]
    private ?int $queueWaitDurationMs = null;

    #[ORM\Column(name: 'total_duration_ms', type: Types::INTEGER, nullable: true)]
    private ?int $totalDurationMs = null;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status;

    #[ORM\Column(name: 'exception_class', type: Types::STRING, length: 255, nullable: true)]
    private ?string $exceptionClass = null;

    #[ORM\Column(name: 'exception_message', type: Types::TEXT, nullable: true)]
    private ?string $exceptionMessage = null;

    #[ORM\Column(name: 'retry_count', type: Types::INTEGER)]
    private int $retryCount = 0;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $metadata = [];

    public const STATUS_PENDING = 'pending';
    public const STATUS_HANDLED = 'handled';
    public const STATUS_FAILED = 'failed';
    public const STATUS_RETRIED = 'retried';

    public const TRANSPORT_SYNC = 'sync';

    public function __construct(
        string $messageClass,
        string $transport = self::TRANSPORT_SYNC,
        bool $isAsync = false,
        ?string $id = null,
    ) {
        $this->id = $id ?? Uuid::v4()->toRfc4122();
        $this->messageClass = $messageClass;
        $this->transport = $transport;
        $this->isAsync = $isAsync;
        $this->dispatchedAt = new DateTimeImmutable();
        $this->status = self::STATUS_PENDING;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getFlowRun(): ?FlowRun
    {
        return $this->flowRun;
    }

    public function setFlowRun(?FlowRun $flowRun): self
    {
        $this->flowRun = $flowRun;
        $this->flowRunId = $flowRun?->getId();

        return $this;
    }

    public function getFlowRunId(): ?string
    {
        return $this->flowRunId ?? $this->flowRun?->getId();
    }

    public function setFlowRunId(string $flowRunId): self
    {
        $this->flowRunId = $flowRunId;

        return $this;
    }

    public function getParentStepId(): ?string
    {
        return $this->parentStepId;
    }

    public function setParentStepId(?string $parentStepId): self
    {
        $this->parentStepId = $parentStepId;

        return $this;
    }

    public function getMessageClass(): string
    {
        return $this->messageClass;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function setMessageId(?string $messageId): self
    {
        $this->messageId = $messageId;

        return $this;
    }

    public function getHandlerClass(): ?string
    {
        return $this->handlerClass;
    }

    public function setHandlerClass(?string $handlerClass): self
    {
        $this->handlerClass = $handlerClass;

        return $this;
    }

    public function getTransport(): string
    {
        return $this->transport;
    }

    public function setTransport(string $transport): self
    {
        $this->transport = $transport;

        return $this;
    }

    public function isAsync(): bool
    {
        return $this->isAsync;
    }

    public function setAsync(bool $isAsync): self
    {
        $this->isAsync = $isAsync;

        return $this;
    }

    public function getDispatchedAt(): DateTimeImmutable
    {
        return $this->dispatchedAt;
    }

    public function getReceivedAt(): ?DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function markReceived(): self
    {
        $this->receivedAt = new DateTimeImmutable();
        $this->calculateQueueWaitDuration();

        return $this;
    }

    public function getHandledAt(): ?DateTimeImmutable
    {
        return $this->handledAt;
    }

    public function getProcessingDurationMs(): ?int
    {
        return $this->processingDurationMs;
    }

    public function getQueueWaitDurationMs(): ?int
    {
        return $this->queueWaitDurationMs;
    }

    public function getTotalDurationMs(): ?int
    {
        return $this->totalDurationMs;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getExceptionClass(): ?string
    {
        return $this->exceptionClass;
    }

    public function getExceptionMessage(): ?string
    {
        return $this->exceptionMessage;
    }

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    public function incrementRetryCount(): self
    {
        ++$this->retryCount;
        $this->status = self::STATUS_RETRIED;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function setMetadata(string $key, mixed $value): self
    {
        $this->metadata[$key] = $value;

        return $this;
    }

    public function markHandled(): self
    {
        $this->handledAt = new DateTimeImmutable();
        $this->status = self::STATUS_HANDLED;
        $this->calculateDurations();

        return $this;
    }

    public function markFailed(Throwable $exception): self
    {
        $this->handledAt = new DateTimeImmutable();
        $this->status = self::STATUS_FAILED;
        $this->exceptionClass = $exception::class;
        $this->exceptionMessage = $exception->getMessage();
        $this->calculateDurations();

        return $this;
    }

    private function calculateQueueWaitDuration(): void
    {
        if (null !== $this->receivedAt && $this->isAsync) {
            $this->queueWaitDurationMs = $this->calculateDifferenceMs($this->dispatchedAt, $this->receivedAt);
        }
    }

    private function calculateDurations(): void
    {
        if (null === $this->handledAt) {
            return;
        }

        // Processing duration: time from received (or dispatched for sync) to handled
        $startTime = $this->receivedAt ?? $this->dispatchedAt;
        $this->processingDurationMs = $this->calculateDifferenceMs($startTime, $this->handledAt);

        // Total duration: from dispatch to handled
        $this->totalDurationMs = $this->calculateDifferenceMs($this->dispatchedAt, $this->handledAt);
    }

    private function calculateDifferenceMs(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        $diff = ($end->getTimestamp() - $start->getTimestamp()) * 1000;
        $microDiff = ((int) $end->format('u') - (int) $start->format('u')) / 1000;

        return (int) ($diff + $microDiff);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'flowRunId' => $this->getFlowRunId(),
            'parentStepId' => $this->parentStepId,
            'messageClass' => $this->messageClass,
            'messageId' => $this->messageId,
            'handlerClass' => $this->handlerClass,
            'transport' => $this->transport,
            'isAsync' => $this->isAsync,
            'dispatchedAt' => $this->dispatchedAt->format('c'),
            'receivedAt' => $this->receivedAt?->format('c'),
            'handledAt' => $this->handledAt?->format('c'),
            'processingDurationMs' => $this->processingDurationMs,
            'queueWaitDurationMs' => $this->queueWaitDurationMs,
            'totalDurationMs' => $this->totalDurationMs,
            'status' => $this->status,
            'exceptionClass' => $this->exceptionClass,
            'exceptionMessage' => $this->exceptionMessage,
            'retryCount' => $this->retryCount,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, ?FlowRun $flowRun = null): self
    {
        $step = new self(
            $data['messageClass'],
            $data['transport'] ?? self::TRANSPORT_SYNC,
            $data['isAsync'] ?? false,
            $data['id'] ?? null,
        );

        if (null !== $flowRun) {
            $step->flowRun = $flowRun;
        }
        $step->flowRunId = $data['flowRunId'] ?? $flowRun?->getId();
        $step->parentStepId = $data['parentStepId'] ?? null;
        $step->messageId = $data['messageId'] ?? null;
        $step->handlerClass = $data['handlerClass'] ?? null;
        $step->dispatchedAt = new DateTimeImmutable($data['dispatchedAt']);
        $step->receivedAt = isset($data['receivedAt']) ? new DateTimeImmutable($data['receivedAt']) : null;
        $step->handledAt = isset($data['handledAt']) ? new DateTimeImmutable($data['handledAt']) : null;
        $step->processingDurationMs = $data['processingDurationMs'] ?? null;
        $step->queueWaitDurationMs = $data['queueWaitDurationMs'] ?? null;
        $step->totalDurationMs = $data['totalDurationMs'] ?? null;
        $step->status = $data['status'] ?? self::STATUS_PENDING;
        $step->exceptionClass = $data['exceptionClass'] ?? null;
        $step->exceptionMessage = $data['exceptionMessage'] ?? null;
        $step->retryCount = $data['retryCount'] ?? 0;
        $step->metadata = $data['metadata'] ?? [];

        return $step;
    }
}
