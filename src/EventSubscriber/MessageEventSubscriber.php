<?php

declare(strict_types=1);

namespace MichalKanak\MessageFlowVisualizerBundle\EventSubscriber;

use MichalKanak\MessageFlowVisualizerBundle\Entity\FlowStep;
use MichalKanak\MessageFlowVisualizerBundle\Stamp\TraceStamp;
use MichalKanak\MessageFlowVisualizerBundle\Storage\StorageInterface;
use MichalKanak\MessageFlowVisualizerBundle\Trace\TraceContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/**
 * Subscribes to Messenger events for additional tracking.
 *
 * This subscriber handles:
 * - Async message dispatch to transports
 * - Worker message reception
 * - Worker message handled/failed events
 * - Retry tracking
 */
class MessageEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly TraceContext $traceContext,
        private readonly bool $enabled = true,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SendMessageToTransportsEvent::class => 'onSendToTransports',
            WorkerMessageReceivedEvent::class => 'onWorkerMessageReceived',
            WorkerMessageHandledEvent::class => 'onWorkerMessageHandled',
            WorkerMessageFailedEvent::class => 'onWorkerMessageFailed',
        ];
    }

    /**
     * Called when a message is sent to async transports.
     */
    public function onSendToTransports(SendMessageToTransportsEvent $event): void
    {
        if (!$this->enabled) {
            return;
        }

        $envelope = $event->getEnvelope();
        $traceStamp = $envelope->last(TraceStamp::class);

        if (null === $traceStamp || !$traceStamp->isSampled) {
            return;
        }

        // If we're inside an active handler, create parent-child relationship
        $currentStep = $this->traceContext->getCurrentStep();
        if (null !== $currentStep) {
            // Create a child stamp for the dispatched message
            $childStamp = $traceStamp->createChild($currentStep->getId());

            // Note: We can't modify the envelope here, the middleware should handle this
            // This event is mainly for tracking that an async dispatch occurred
            $currentStep->setMetadata('dispatchedChildren', [
                ...($currentStep->getMetadata()['dispatchedChildren'] ?? []),
                [
                    'messageClass' => $envelope->getMessage()::class,
                    'transport' => implode(',', array_keys($event->getSenders())),
                ],
            ]);
        }
    }

    /**
     * Called when a worker receives a message from transport.
     */
    public function onWorkerMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        if (!$this->enabled) {
            return;
        }

        // Timing is handled in middleware when it detects ReceivedStamp
    }

    /**
     * Called when a worker successfully handles a message.
     */
    public function onWorkerMessageHandled(WorkerMessageHandledEvent $event): void
    {
        if (!$this->enabled) {
            return;
        }

        // Success handling is done in middleware
    }

    /**
     * Called when a worker fails to handle a message.
     */
    public function onWorkerMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if (!$this->enabled) {
            return;
        }

        $envelope = $event->getEnvelope();
        $traceStamp = $envelope->last(TraceStamp::class);

        if (null === $traceStamp || !$traceStamp->isSampled) {
            return;
        }

        // Track retry
        if ($event->willRetry()) {
            // Load flow run and update step retry count
            $flowRun = $this->storage->findFlowRun($traceStamp->flowRunId);
            if (null !== $flowRun) {
                foreach ($flowRun->getSteps() as $step) {
                    if ($step->getMessageClass() === $envelope->getMessage()::class
                        && FlowStep::STATUS_FAILED === $step->getStatus()) {
                        $step->incrementRetryCount();
                        $this->storage->saveFlowStep($step);
                        break;
                    }
                }
            }
        }
    }
}
