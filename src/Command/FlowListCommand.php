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
 * List recent flow runs with filtering options.
 */
#[AsCommand(
    name: 'messenger:flow:list',
    description: 'List recent message flows',
)]
class FlowListCommand extends Command
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly int $slowThresholdMs = 500,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of flows to display', '20')
            ->addOption('page', 'p', InputOption::VALUE_REQUIRED, 'Page number (1-indexed)', '1')
            ->addOption('status', 's', InputOption::VALUE_REQUIRED, 'Filter by status (running, completed, failed)')
            ->addOption('slow', null, InputOption::VALUE_NONE, 'Show only slow flows (>500ms)')
            ->addOption('message-class', 'm', InputOption::VALUE_REQUIRED, 'Filter by message class name (partial match)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limit = (int) $input->getOption('limit');
        $page = max(1, (int) $input->getOption('page'));
        $offset = ($page - 1) * $limit;
        $status = $input->getOption('status');
        $slowOnly = $input->getOption('slow');
        $messageClass = $input->getOption('message-class');

        $result = $this->storage->findRecentFlowRunsPaginated($limit * 3, $offset, $status);
        $flows = $result->items;

        $filteredFlows = [];
        foreach ($flows as $flow) {
            if ($slowOnly && ($flow->getDurationMs() ?? 0) <= $this->slowThresholdMs) {
                continue;
            }

            if ($messageClass) {
                $matchFound = false;
                foreach ($flow->getSteps() as $step) {
                    if (false !== stripos($step->getMessageClass(), $messageClass)) {
                        $matchFound = true;
                        break;
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

        if (0 === \count($filteredFlows)) {
            $io->note('No flows found matching your criteria');

            return Command::SUCCESS;
        }

        $io->title('Recent Message Flows');

        $rows = [];
        foreach ($filteredFlows as $flow) {
            $steps = $flow->getSteps();
            $messageClasses = array_unique(array_map(
                fn ($s) => $this->shortenClassName($s->getMessageClass()),
                $steps,
            ));

            $duration = $flow->getDurationMs();
            $isSlow = null !== $duration && $duration > $this->slowThresholdMs;

            $rows[] = [
                substr($flow->getId(), 0, 8).'...',
                $this->formatStatus($flow->getStatus()),
                $flow->getStartedAt()->format('Y-m-d H:i:s'),
                $this->formatDuration($duration, $isSlow),
                \count($steps),
                implode(', ', \array_slice($messageClasses, 0, 3)).(\count($messageClasses) > 3 ? '...' : ''),
            ];
        }

        $io->table(
            ['ID', 'Status', 'Started At', 'Duration', 'Steps', 'Messages'],
            $rows,
        );

        $io->note(\sprintf('Showing %d of %d flows (page %d).', \count($filteredFlows), $result->total, $page));

        return Command::SUCCESS;
    }

    private function formatStatus(string $status): string
    {
        return match ($status) {
            'completed' => "<fg=green>$status</>",
            'failed' => "<fg=red>$status</>",
            'running' => "<fg=yellow>$status</>",
            default => $status,
        };
    }

    private function formatDuration(?int $duration, bool $isSlow): string
    {
        if (null === $duration) {
            return '-';
        }

        $formatted = $duration.' ms';

        if ($isSlow) {
            return "<fg=yellow>⚠️ $formatted</>";
        }

        return $formatted;
    }

    private function shortenClassName(string $className): string
    {
        $parts = explode('\\', $className);

        return end($parts);
    }
}
