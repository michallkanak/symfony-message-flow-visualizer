<?php

declare(strict_types=1);

namespace MichalKanak\MessageFlowVisualizerBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Message Flow Visualizer Bundle for Symfony Messenger.
 *
 * Provides visual tracing and debugging capabilities for message flows,
 * including DAG visualization, timeline views, and profiler integration.
 */
class MessageFlowVisualizerBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
