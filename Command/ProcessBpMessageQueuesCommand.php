<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Command;

use MauticPlugin\MauticBpMessageBundle\Model\BpMessageModel;
use MauticPlugin\MauticBpMessageBundle\Model\BpMessageEmailModel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to process BpMessage queues (send pending messages)
 */
class ProcessBpMessageQueuesCommand extends Command
{
    private BpMessageModel $bpMessageModel;
    private BpMessageEmailModel $bpMessageEmailModel;

    public function __construct(
        BpMessageModel $bpMessageModel,
        BpMessageEmailModel $bpMessageEmailModel
    ) {
        $this->bpMessageModel = $bpMessageModel;
        $this->bpMessageEmailModel = $bpMessageEmailModel;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mautic:bpmessage:process')
            ->setDescription('Process BpMessage queues and send pending messages')
            ->setHelp(
                <<<'EOT'
The <info>%command.name%</info> command processes open BpMessage lots and sends pending messages.

<info>php %command.full_name%</info>

Process lots that should be closed (time window expired or batch size reached):
<info>php %command.full_name%</info>

Force close all open lots:
<info>php %command.full_name% --force-close</info>

Retry failed messages:
<info>php %command.full_name% --retry</info>

Process a specific lot:
<info>php %command.full_name% --lot-id=123</info>

Set maximum retries for failed messages:
<info>php %command.full_name% --retry --max-retries=5</info>
EOT
            )
            ->addOption(
                'lot-id',
                'l',
                InputOption::VALUE_REQUIRED,
                'Process a specific lot by ID'
            )
            ->addOption(
                'force-close',
                'f',
                InputOption::VALUE_NONE,
                'Force close all open lots regardless of time/count criteria'
            )
            ->addOption(
                'retry',
                'r',
                InputOption::VALUE_NONE,
                'Retry failed messages'
            )
            ->addOption(
                'max-retries',
                'm',
                InputOption::VALUE_REQUIRED,
                'Maximum retry count for failed messages',
                '3'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('BpMessage Queue Processor');

        // Process specific lot
        if ($lotId = $input->getOption('lot-id')) {
            return $this->processSpecificLot((int) $lotId, $io);
        }

        // Retry failed messages
        if ($input->getOption('retry')) {
            return $this->retryFailedMessages($input, $io);
        }

        // Process open lots
        return $this->processOpenLots($input, $io);
    }

    private function processSpecificLot(int $lotId, SymfonyStyle $io): int
    {
        $io->section("Processing lot #{$lotId}");

        try {
            $success = $this->bpMessageModel->forceCloseLot($lotId);

            if ($success) {
                $io->success("Lot #{$lotId} processed successfully");
                return Command::SUCCESS;
            }

            $io->error("Failed to process lot #{$lotId}");
            return Command::FAILURE;
        } catch (\Exception $e) {
            $io->error("Error processing lot #{$lotId}: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    private function retryFailedMessages(InputInterface $input, SymfonyStyle $io): int
    {
        $maxRetries = (int) $input->getOption('max-retries');

        $io->section('Retrying Failed Messages');
        $io->text("Maximum retries: {$maxRetries}");

        try {
            $retried = $this->bpMessageModel->retryFailedMessages($maxRetries);

            if ($retried > 0) {
                $io->success("Retried {$retried} failed messages");
            } else {
                $io->info('No failed messages to retry');
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error("Error retrying failed messages: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    private function processOpenLots(InputInterface $input, SymfonyStyle $io): int
    {
        $forceClose = $input->getOption('force-close');

        $io->section('Processing Open Lots');

        if ($forceClose) {
            $io->warning('Force close mode enabled - all open lots will be processed');
        }

        try {
            // First, process orphaned CREATING lots (stuck lots)
            $io->text('Checking for orphaned CREATING lots...');
            $orphanedStats = $this->bpMessageEmailModel->processOrphanedCreatingLots(5);

            if ($orphanedStats['processed'] > 0) {
                $io->warning("Found {$orphanedStats['processed']} orphaned CREATING lots, marked {$orphanedStats['marked_failed']} as FAILED");
            }

            // Process regular message lots (SMS/WhatsApp/RCS)
            $io->text('Processing message lots (SMS/WhatsApp/RCS)...');
            $messageStats = $this->bpMessageModel->processOpenLots($forceClose);

            // Process email lots
            $io->text('Processing email lots...');
            $emailStats = $this->bpMessageEmailModel->processOpenLots($forceClose);

            // Combine statistics
            $totalStats = [
                'processed' => $messageStats['processed'] + $emailStats['processed'],
                'succeeded' => $messageStats['succeeded'] + $emailStats['succeeded'],
                'failed' => $messageStats['failed'] + $emailStats['failed'],
            ];

            $io->table(
                ['Metric', 'Count'],
                [
                    ['Message Lots Processed', $messageStats['processed']],
                    ['Email Lots Processed', $emailStats['processed']],
                    ['Total Lots Processed', $totalStats['processed']],
                    ['Total Succeeded', $totalStats['succeeded']],
                    ['Total Failed', $totalStats['failed']],
                ]
            );

            if ($totalStats['processed'] === 0) {
                $io->info('No lots to process');
            } elseif ($totalStats['failed'] > 0) {
                $io->warning("Processed {$totalStats['processed']} lots, but {$totalStats['failed']} failed");
                return Command::FAILURE;
            } else {
                $io->success("Successfully processed {$totalStats['processed']} lots");
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error("Error processing lots: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
