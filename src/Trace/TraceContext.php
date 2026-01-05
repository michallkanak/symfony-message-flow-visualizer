<?php

declare(strict_types=1);

namespace MichalKanak\MessageFlowVisualizerBundle\Trace;

use MichalKanak\MessageFlowVisualizerBundle\Entity\FlowRun;
use MichalKanak\MessageFlowVisualizerBundle\Entity\FlowStep;
use MichalKanak\MessageFlowVisualizerBundle\Stamp\TraceStamp;

/**
 * Thread-local context for trace propagation.
 *
 * Maintains the current trace context during request/message processing,
 * allowing nested handlers to access parent context information.
 */
class TraceContext
{
    private ?FlowRun $currentFlowRun = null;
    private ?FlowStep $currentStep = null;
    private ?TraceStamp $currentStamp = null;

    /** @var FlowStep[] */
    private array $stepStack = [];

    public function getCurrentFlowRun(): ?FlowRun
    {
        return $this->currentFlowRun;
    }

    public function setCurrentFlowRun(?FlowRun $flowRun): void
    {
        $this->currentFlowRun = $flowRun;
    }

    public function getCurrentStep(): ?FlowStep
    {
        return $this->currentStep;
    }

    public function setCurrentStep(?FlowStep $step): void
    {
        $this->currentStep = $step;
    }

    public function getCurrentStamp(): ?TraceStamp
    {
        return $this->currentStamp;
    }

    public function setCurrentStamp(?TraceStamp $stamp): void
    {
        $this->currentStamp = $stamp;
    }

    /**
     * Push a step onto the stack (entering handler).
     */
    public function pushStep(FlowStep $step): void
    {
        if (null !== $this->currentStep) {
            $this->stepStack[] = $this->currentStep;
        }
        $this->currentStep = $step;
    }

    /**
     * Pop a step from the stack (leaving handler).
     */
    public function popStep(): ?FlowStep
    {
        $step = $this->currentStep;

        if (\count($this->stepStack) > 0) {
            $this->currentStep = array_pop($this->stepStack);
        } else {
            $this->currentStep = null;
        }

        return $step;
    }

    /**
     * Check if we're currently inside an active trace.
     */
    public function isActive(): bool
    {
        return null !== $this->currentFlowRun;
    }

    /**
     * Get the current parent step ID for child messages.
     */
    public function getParentStepId(): ?string
    {
        return $this->currentStep?->getId();
    }

    /**
     * Reset the context (e.g., after request completes).
     */
    public function reset(): void
    {
        $this->currentFlowRun = null;
        $this->currentStep = null;
        $this->currentStamp = null;
        $this->stepStack = [];
    }
}
