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
 * Clean up old flow data.
 */
#[AsCommand(
    name: 'messenger:flow:cleanup',
    description: 'Clean up old message flow data',
)]
class FlowCleanupCommand extends Command
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly int $retentionDays = 7,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'older-than',
                null,
                InputOption::VALUE_REQUIRED,
                'Delete flows older than this (e.g., "7 days")',
                \sprintf('%d days', $this->retentionDays),
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be deleted without actually deleting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $olderThan = $input->getOption('older-than');
        $dryRun = $input->getOption('dry-run');

        try {
            $before = new DateTimeImmutable('-'.$olderThan);
        } catch (Exception $e) {
            $io->error('Invalid time format: '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->title('Message Flow Cleanup');
        $io->text(\sprintf('Cleaning up flows older than: %s', $before->format('Y-m-d H:i:s')));

        if ($dryRun) {
            $io->note('Dry run mode - no data will be deleted');

            // Get count of flows that would be deleted
            $allFlows = $this->storage->findRecentFlowRuns(10000);
            $toDelete = 0;
            foreach ($allFlows as $flow) {
                if ($flow->getStartedAt() < $before) {
                    ++$toDelete;
                }
            }

            $io->info(\sprintf('Would delete %d flow(s)', $toDelete));

            return Command::SUCCESS;
        }

        if (!$io->confirm('Are you sure you want to delete old flow data?', false)) {
            $io->note('Operation cancelled');

            return Command::SUCCESS;
        }

        $deleted = $this->storage->cleanup($before);

        $io->success(\sprintf('Deleted %d flow(s)', $deleted));

        return Command::SUCCESS;
    }
}
