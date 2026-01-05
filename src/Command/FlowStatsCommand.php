<?php

declare(strict_types=1);

namespace MichalKanak\MessageFlowVisualizerBundle\Command;

use DateTimeImmutable;
use Exception;
use MichalKanak\MessageFlowVisualizerBundle\Storage\StorageInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Display flow statistics.
 */
#[AsCommand(
    name: 'messenger:flow:stats',
    description: 'Show message flow statistics',
)]
class FlowStatsCommand extends Command
{
    public function __construct(
        private readonly StorageInterface $storage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Start time (e.g., "1 hour ago")', '1 day ago')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'End time (e.g., "now")', 'now');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $fromStr = $input->getOption('from');
        $toStr = $input->getOption('to');

        try {
            $from = new DateTimeImmutable($fromStr);
            $to = new DateTimeImmutable($toStr);
        } catch (Exception $e) {
            $io->error('Invalid date format: '.$e->getMessage());

            return Command::FAILURE;
        }

        $stats = $this->storage->getStatistics($from, $to);

        $io->title('Message Flow Statistics');
        $io->text(\sprintf('Period: %s to %s', $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')));

        // Overview
        $io->section('Overview');
        $io->table(
            ['Metric', 'Value'],
            [
                ['Total Flows', $stats['totalFlows']],
                ['Completed Flows', \sprintf('<fg=green>%d</> (%.1f%%)', $stats['completedFlows'], $this->percentage($stats['completedFlows'], $stats['totalFlows']))],
                ['Failed Flows', \sprintf('<fg=red>%d</> (%.1f%%)', $stats['failedFlows'], $this->percentage($stats['failedFlows'], $stats['totalFlows']))],
                ['Average Duration', \sprintf('%.2f ms', $stats['avgDurationMs'])],
            ],
        );

        // Message classes
        if (\count($stats['messageClasses']) > 0) {
            $io->section('Top Message Classes');

            // Sort by count descending
            arsort($stats['messageClasses']);
            $topClasses = \array_slice($stats['messageClasses'], 0, 10, true);

            $rows = [];
            foreach ($topClasses as $class => $count) {
                $rows[] = [
                    $this->shortenClassName($class),
                    $count,
                    \sprintf('%.1f%%', $this->percentage($count, array_sum($stats['messageClasses']))),
                ];
            }

            $io->table(['Message Class', 'Count', 'Percentage'], $rows);
        }

        return Command::SUCCESS;
    }

    private function percentage(int $part, int $total): float
    {
        if (0 === $total) {
            return 0.0;
        }

        return ($part / $total) * 100;
    }

    private function shortenClassName(string $className): string
    {
        $parts = explode('\\', $className);

        return end($parts);
    }
}
