<?php

declare(strict_types=1);

namespace MichalKanak\MessageFlowVisualizerBundle\Middleware;

use MichalKanak\MessageFlowVisualizerBundle\DataCollector\MessageFlowDataCollector;
use MichalKanak\MessageFlowVisualizerBundle\Entity\FlowRun;
use MichalKanak\MessageFlowVisualizerBundle\Entity\FlowStep;
use MichalKanak\MessageFlowVisualizerBundle\Stamp\TraceStamp;
use MichalKanak\MessageFlowVisualizerBundle\Storage\StorageInterface;
use MichalKanak\MessageFlowVisualizerBundle\Trace\SamplingDecider;
use MichalKanak\MessageFlowVisualizerBundle\Trace\TraceContext;
use MichalKanak\MessageFlowVisualizerBundle\Trace\TraceIdGenerator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Throwable;

/**
 * Middleware that traces message flow through the bus.
 *
 * This middleware:
 * - Creates or inherits trace context
 * - Records flow steps for each message
 * - Tracks timing (processing, queue wait, total)
 * - Propagates trace to child messages
 */
class TraceMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly TraceContext $traceContext,
        private readonly TraceIdGenerator $idGenerator,
        private readonly SamplingDecider $samplingDecider,
        private readonly bool $enabled = true,
        private readonly ?MessageFlowDataCollector $dataCollector = null,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (!$this->enabled) {
            return $stack->next()->handle($envelope, $stack);
        }

        $traceStamp = $envelope->last(TraceStamp::class);
        $isNewFlow = false;

        // Check if this is sampled
        if (null !== $traceStamp && !$traceStamp->isSampled) {
            // Not sampled - skip tracing
            return $stack->next()->handle($envelope, $stack);
        }

        // Determine if this is a new flow or continuation
        if (null === $traceStamp) {
            // No trace stamp - check if we have an active context (nested message from handler)
            $currentStamp = $this->traceContext->getCurrentStamp();

            // If parent was not sampled, inherit that decision
            if (null !== $currentStamp && !$currentStamp->isSampled) {
                $notSampledStamp = new TraceStamp(
                    traceId: $currentStamp->traceId,
                    flowRunId: $currentStamp->flowRunId,
                    isSampled: false,
                );
                $envelope = $envelope->with($notSampledStamp);

                return $stack->next()->handle($envelope, $stack);
            }

            // If parent was sampled, inherit that flow
            if ($this->traceContext->isActive()) {
                $flowRun = $this->traceContext->getCurrentFlowRun();

                if (null !== $currentStamp && null !== $flowRun) {
                    $traceStamp = new TraceStamp(
                        traceId: $currentStamp->traceId,
                        flowRunId: $flowRun->getId(),
                        parentStepId: $this->traceContext->getParentStepId(),
                        correlationId: $this->idGenerator->generateCorrelationId(),
                        isSampled: true,
                    );
                    $envelope = $envelope->with($traceStamp);
                }
            }

            // Still no stamp - check if we should sample this new flow
            if (null === $traceStamp) {
                if (!$this->samplingDecider->shouldSample()) {
                    // Not sampled - create stamp with isSampled=false
                    $notSampledStamp = new TraceStamp(
                        traceId: $this->idGenerator->generateTraceId(),
                        flowRunId: $this->idGenerator->generateFlowRunId(),
                        isSampled: false,
                    );
                    $envelope = $envelope->with($notSampledStamp);

                    // Set context so nested messages inherit "not sampled" decision
                    $this->traceContext->setCurrentStamp($notSampledStamp);

                    try {
                        return $stack->next()->handle($envelope, $stack);
                    } finally {
                        // Reset context after handler completes
                        $this->traceContext->setCurrentStamp(null);
                    }
                }

                // Create new flow run
                $flowRun = new FlowRun(
                    id: $this->idGenerator->generateFlowRunId(),
                    traceId: $this->idGenerator->generateTraceId(),
                    initiator: $this->determineInitiator(),
                );

                $traceStamp = new TraceStamp(
                    traceId: $flowRun->getTraceId(),
                    flowRunId: $flowRun->getId(),
                    correlationId: $this->idGenerator->generateCorrelationId(),
                    isSampled: true,
                );

                $envelope = $envelope->with($traceStamp);
                $this->traceContext->setCurrentFlowRun($flowRun);
                $this->storage->saveFlowRun($flowRun);
                $isNewFlow = true;
            }
        } else {
            // Existing trace - load flow run if not in context
            if (null === $this->traceContext->getCurrentFlowRun()) {
                $flowRun = $this->storage->findFlowRun($traceStamp->flowRunId);
                if (null === $flowRun) {
                    // Flow run not found - create it
                    $flowRun = new FlowRun(
                        id: $traceStamp->flowRunId,
                        traceId: $traceStamp->traceId,
                    );
                    $this->storage->saveFlowRun($flowRun);
                }
                $this->traceContext->setCurrentFlowRun($flowRun);
            }
        }

        // Check if received from async transport
        $receivedStamp = $envelope->last(ReceivedStamp::class);
        $isReceivedAsync = null !== $receivedStamp;

        // Try to load existing step if this is an async message being received
        $step = null;
        $isExistingStep = false;

        // stepId is always available in TraceStamp
        $stepId = $traceStamp->stepId;

        if ($isReceivedAsync && null !== $stepId) {
            // Load existing flow run and find the step
            $flowRun = $this->storage->findFlowRun($traceStamp->flowRunId);
            if (null !== $flowRun) {
                $this->traceContext->setCurrentFlowRun($flowRun);
                foreach ($flowRun->getSteps() as $existingStep) {
                    if ($existingStep->getId() === $stepId) {
                        $step = $existingStep;
                        $isExistingStep = true;
                        break;
                    }
                }
            }
        }

        // Create new step if we didn't find existing one
        if (null === $step) {
            $step = $this->createFlowStep($envelope, $traceStamp);

            // For NEW steps, add stepId to TraceStamp BEFORE sending through stack
            // This ensures async messages carry the stepId for correlation
            $updatedTraceStamp = $traceStamp->withStepId($step->getId());
            $envelope = $envelope->withoutAll(TraceStamp::class)->with($updatedTraceStamp);
            $traceStamp = $updatedTraceStamp;
        }

        $this->traceContext->pushStep($step);
        $this->traceContext->setCurrentStamp($traceStamp);

        // Mark received for async messages
        if ($isReceivedAsync) {
            $step->markReceived();
        }

        // Add step to FlowRun (only for new steps)
        $flowRun = $this->traceContext->getCurrentFlowRun();
        if (null !== $flowRun && !$isExistingStep) {
            $flowRun->addStep($step);
        }
        // Note: FlowRun is saved at the end (root completion or worker done)

        try {
            // Execute next middleware/handler
            $envelope = $stack->next()->handle($envelope, $stack);

            // Check if message was sent to async transport (after SendMessageMiddleware)
            $sentStamp = $envelope->last(SentStamp::class);
            $sentToAsync = null !== $sentStamp && null !== $sentStamp->getSenderAlias();

            if ($sentToAsync) {
                // Message was sent to async transport, not handled yet
                $senderAlias = $sentStamp->getSenderAlias();
                if (null !== $senderAlias) {
                    $step->setTransport($senderAlias);
                }
                $step->setAsync(true);
            // Status stays as 'pending' - will be updated when worker processes it
            } else {
                // Message was handled synchronously
                $step->markHandled();

                // Extract handler class from HandledStamp
                $handledStamp = $envelope->last(HandledStamp::class);
                if (null !== $handledStamp) {
                    $step->setHandlerClass($handledStamp->getHandlerName());
                }
            }

            // Save flow run - at specific points to balance data integrity and performance
            $flowRun = $this->traceContext->getCurrentFlowRun();

            // Save if:
            // 1. Root flow completed (all sync steps done)
            // 2. Worker processed async step
            // 3. Step was sent to async transport (so worker can find it)
            $shouldSave = $isNewFlow || $isReceivedAsync || $sentToAsync;

            if ($shouldSave && null !== $flowRun) {
                if ($isNewFlow) {
                    $flowRun->markCompleted();
                }
                $this->storage->saveFlowRun($flowRun);
            }

            return $envelope;
        } catch (Throwable $exception) {
            // Mark step as failed
            $step->markFailed($exception);

            $flowRun = $this->traceContext->getCurrentFlowRun();
            if (null !== $flowRun) {
                $this->storage->saveFlowRun($flowRun);
            }

            // Update flow run status if this was root message
            if ($isNewFlow && null !== $flowRun) {
                $flowRun->markFailed();
                $this->storage->saveFlowRun($flowRun);
            }

            throw $exception;
        } finally {
            $this->traceContext->popStep();

            // Reset context if we started the flow
            if ($isNewFlow && null === $this->traceContext->getCurrentStep()) {
                // Collect flow for profiler BEFORE reset
                $flowRun = $this->traceContext->getCurrentFlowRun();
                if (null !== $flowRun && null !== $this->dataCollector) {
                    $this->dataCollector->addCollectedFlow($flowRun);
                }

                $this->traceContext->reset();
            }
        }
    }

    private function createFlowStep(Envelope $envelope, TraceStamp $traceStamp): FlowStep
    {
        $message = $envelope->getMessage();
        $messageClass = $message::class;

        // Determine transport
        $transport = FlowStep::TRANSPORT_SYNC;
        $isAsync = false;

        // Check if received from async transport (processing queued message)
        $receivedStamp = $envelope->last(ReceivedStamp::class);
        if (null !== $receivedStamp) {
            $transport = $receivedStamp->getTransportName();
            $isAsync = true;
        }

        // Check if sent to async transport (dispatching to queue)
        $sentStamp = $envelope->last(SentStamp::class);
        if (null !== $sentStamp) {
            $senderAlias = $sentStamp->getSenderAlias();
            if (null !== $senderAlias) {
                $transport = $senderAlias;
                $isAsync = true;
            }
        }

        // Check if sent to failure transport
        $failureStamp = $envelope->last(SentToFailureTransportStamp::class);
        if (null !== $failureStamp) {
            $transport = 'failure';
        }

        $step = new FlowStep(
            messageClass: $messageClass,
            transport: $transport,
            isAsync: $isAsync,
        );

        $step->setFlowRunId($traceStamp->flowRunId);
        $step->setParentStepId($traceStamp->parentStepId);

        // Set message ID if available
        $messageIdStamp = $envelope->last(TransportMessageIdStamp::class);
        if (null !== $messageIdStamp) {
            $step->setMessageId((string) $messageIdStamp->getId());
        }

        return $step;
    }

    private function determineInitiator(): string
    {
        // Try to determine what initiated this flow
        if (\PHP_SAPI === 'cli') {
            global $argv;

            return 'cli:'.($argv[0] ?? 'unknown');
        }

        // HTTP request
        $requestUri = $_SERVER['REQUEST_URI'] ?? null;
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? null;

        if (null !== $requestUri) {
            return \sprintf('http:%s %s', $requestMethod ?? 'UNKNOWN', $requestUri);
        }

        return 'unknown';
    }
}
