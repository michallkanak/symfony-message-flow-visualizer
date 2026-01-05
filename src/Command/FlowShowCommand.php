<?php

declare(strict_types=1);

namespace MichalKanak\MessageFlowVisualizerBundle\Command;

use MichalKanak\MessageFlowVisualizerBundle\Storage\StorageInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Display details of a specific flow run.
 */
#[AsCommand(
    name: 'messenger:flow:show',
    description: 'Show details of a specific message flow',
)]
class FlowShowCommand extends Command
{
    public function __construct(
        private readonly StorageInterface $storage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Flow run ID')
            ->addOption('trace', null, InputOption::VALUE_REQUIRED, 'Trace ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $id = $input->getOption('id');
        $traceId = $input->getOption('trace');

        if (null === $id && null === $traceId) {
            $io->error('Please provide either --id or --trace option');

            return Command::FAILURE;
        }

        $flowRun = null !== $id
            ? $this->storage->findFlowRun($id)
            : $this->storage->findFlowRunByTraceId($traceId);

        if (null === $flowRun) {
            $io->error('Flow run not found');

            return Command::FAILURE;
        }

        // Display flow run info
        $io->title('Message Flow Details');

        $io->table(
            ['Property', 'Value'],
            [
                ['ID', $flowRun->getId()],
                ['Trace ID', $flowRun->getTraceId()],
                ['Status', $this->formatStatus($flowRun->getStatus())],
                ['Started At', $flowRun->getStartedAt()->format('Y-m-d H:i:s.u')],
                ['Finished At', $flowRun->getFinishedAt()?->format('Y-m-d H:i:s.u') ?? '-'],
                ['Duration', null !== $flowRun->getDurationMs() ? $flowRun->getDurationMs().' ms' : '-'],
                ['Initiator', $flowRun->getInitiator() ?? '-'],
            ],
        );

        // Display steps
        $steps = $flowRun->getSteps();

        if (0 === \count($steps)) {
            $io->note('No steps recorded for this flow');

            return Command::SUCCESS;
        }

        $io->section('Flow Steps');

        $stepRows = [];
        foreach ($steps as $index => $step) {
            $stepRows[] = [
                $index + 1,
                $this->shortenClassName($step->getMessageClass()),
                $step->getHandlerClass() ? $this->shortenClassName($step->getHandlerClass()) : '-',
                $step->getTransport(),
                $step->isAsync() ? 'Yes' : 'No',
                $this->formatStatus($step->getStatus()),
                null !== $step->getProcessingDurationMs() ? $step->getProcessingDurationMs().' ms' : '-',
                null !== $step->getQueueWaitDurationMs() ? $step->getQueueWaitDurationMs().' ms' : '-',
                null !== $step->getTotalDurationMs() ? $step->getTotalDurationMs().' ms' : '-',
            ];
        }

        $io->table(
            ['#', 'Message', 'Handler', 'Transport', 'Async', 'Status', 'Processing', 'Queue Wait', 'Total'],
            $stepRows,
        );

        // Display tree structure
        $io->section('Flow Tree');
        $this->displayTree($io, $steps);

        // Display errors if any
        $failedSteps = array_filter($steps, fn ($s) => 'failed' === $s->getStatus());
        if (\count($failedSteps) > 0) {
            $io->section('Errors');
            foreach ($failedSteps as $step) {
                $io->error(\sprintf(
                    "%s: %s\n%s",
                    $this->shortenClassName($step->getMessageClass()),
                    $step->getExceptionClass() ?? 'Unknown',
                    $step->getExceptionMessage() ?? '',
                ));
            }
        }

        return Command::SUCCESS;
    }

    private function formatStatus(string $status): string
    {
        return match ($status) {
            'completed', 'handled' => "<fg=green>$status</>",
            'failed' => "<fg=red>$status</>",
            'running', 'pending' => "<fg=yellow>$status</>",
            'retried' => "<fg=blue>$status</>",
            default => $status,
        };
    }

    private function shortenClassName(string $className): string
    {
        $parts = explode('\\', $className);

        return end($parts);
    }

    /**
     * @param \MichalKanak\MessageFlowVisualizerBundle\Entity\FlowStep[] $steps
     */
    private function displayTree(SymfonyStyle $io, array $steps): void
    {
        // Build parent-child map
        $byParent = [];
        $rootSteps = [];

        foreach ($steps as $step) {
            $parentId = $step->getParentStepId();
            if (null === $parentId) {
                $rootSteps[] = $step;
            } else {
                $byParent[$parentId][] = $step;
            }
        }

        // Display tree recursively
        foreach ($rootSteps as $step) {
            $this->displayStepTree($io, $step, $byParent, 0);
        }
    }

    /**
     * @param array<string, \MichalKanak\MessageFlowVisualizerBundle\Entity\FlowStep[]> $byParent
     */
    private function displayStepTree(
        SymfonyStyle $io,
        \MichalKanak\MessageFlowVisualizerBundle\Entity\FlowStep $step,
        array $byParent,
        int $depth,
    ): void {
        $indent = str_repeat('  ', $depth);
        $prefix = $depth > 0 ? '└─ ' : '';
        $status = $this->formatStatus($step->getStatus());
        $async = $step->isAsync() ? ' [async]' : '';
        $duration = null !== $step->getTotalDurationMs() ? " ({$step->getTotalDurationMs()}ms)" : '';

        $io->writeln(\sprintf(
            '%s%s%s%s%s %s',
            $indent,
            $prefix,
            $this->shortenClassName($step->getMessageClass()),
            $async,
            $duration,
            $status,
        ));

        $children = $byParent[$step->getId()] ?? [];
        foreach ($children as $child) {
            $this->displayStepTree($io, $child, $byParent, $depth + 1);
        }
    }
}
