<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Command;

use MauticPlugin\MauticBpMessageBundle\Model\BpMessageEmailModel;
use MauticPlugin\MauticBpMessageBundle\Model\BpMessageModel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to process BpMessage queues (send pending messages).
 */
class ProcessBpMessageQueuesCommand extends Command
{
    private BpMessageModel $bpMessageModel;
    private BpMessageEmailModel $bpMessageEmailModel;

    public function __construct(
        BpMessageModel $bpMessageModel,
        BpMessageEmailModel $bpMessageEmailModel,
    ) {
        $this->bpMessageModel      = $bpMessageModel;
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
        // Process specific lot
        if ($lotId = $input->getOption('lot-id')) {
            return $this->processSpecificLot((int) $lotId, $output);
        }

        // Retry failed messages
        if ($input->getOption('retry')) {
            return $this->retryFailedMessages($input, $output);
        }

        // Process open lots
        return $this->processOpenLots($input, $output);
    }

    private function processSpecificLot(int $lotId, OutputInterface $output): int
    {
        $output->writeln("Processing lot #{$lotId}");

        try {
            // First, check if the lot exists and determine its type
            $lot = $this->bpMessageModel->getLotById($lotId);

            if (null === $lot) {
                $output->writeln("Error: Lot #{$lotId} not found");

                return Command::FAILURE;
            }

            // Route to the appropriate model based on lot type
            if ($lot->isEmailLot()) {
                $output->writeln("Lot #{$lotId} is an EMAIL lot (idQuotaSettings = 0)");
                $success = $this->bpMessageEmailModel->forceCloseLot($lotId);
            } else {
                $output->writeln("Lot #{$lotId} is a MESSAGE lot (idQuotaSettings = {$lot->getIdQuotaSettings()})");
                $success = $this->bpMessageModel->forceCloseLot($lotId);
            }

            if ($success) {
                $output->writeln("Lot #{$lotId} processed successfully");

                return Command::SUCCESS;
            }

            $output->writeln("Failed to process lot #{$lotId}");

            // Try to get lot details to show error message
            $lot = $this->bpMessageModel->getLotById($lotId);
            if ($lot && $lot->getErrorMessage()) {
                $output->writeln('Error details:');
                $output->writeln($lot->getErrorMessage());
                $output->writeln('');
            }

            // Get failed messages details
            $em             = $this->bpMessageModel->getEntityManager();
            $failedMessages = $em->createQueryBuilder()
                ->select('q.id', 'IDENTITY(q.lead) as leadId', 'q.errorMessage', 'q.retryCount')
                ->from('MauticPlugin\MauticBpMessageBundle\Entity\BpMessageQueue', 'q')
                ->where('q.lot = :lotId')
                ->andWhere('q.status = :status')
                ->setParameter('lotId', $lotId)
                ->setParameter('status', 'FAILED')
                ->setMaxResults(5)
                ->getQuery()
                ->getArrayResult();

            if (!empty($failedMessages)) {
                $output->writeln('Failed Messages Sample (first 5):');
                foreach ($failedMessages as $msg) {
                    $errorMsg = substr($msg['errorMessage'] ?? 'No error message', 0, 80);
                    $output->writeln("  Queue ID: {$msg['id']}, Lead ID: {$msg['leadId']}, Retries: {$msg['retryCount']}, Error: {$errorMsg}");
                }
                $output->writeln('');
            }

            $output->writeln("Fix the error and retry with: php bin/console mautic:bpmessage:process --lot-id={$lotId}");
            $output->writeln("Or check logs: tail -100 var/logs/mautic_prod.log | grep 'lot.*{$lotId}'");
            $output->writeln('');

            return Command::FAILURE;
        } catch (\Exception $e) {
            $output->writeln("Error processing lot #{$lotId}: {$e->getMessage()}");
            $output->writeln('Exception type: '.get_class($e));
            $output->writeln('Stack trace:');
            $output->writeln($e->getTraceAsString());

            return Command::FAILURE;
        }
    }

    private function retryFailedMessages(InputInterface $input, OutputInterface $output): int
    {
        $maxRetries = (int) $input->getOption('max-retries');

        $output->writeln('Retrying BpMessage failed messages');
        $output->writeln("Maximum retries: {$maxRetries}");

        try {
            $retried = $this->bpMessageModel->retryFailedMessages($maxRetries);

            $output->writeln("{$retried} message(s) retried");
            $output->writeln('');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("Error retrying failed messages: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }

    private function processOpenLots(InputInterface $input, OutputInterface $output): int
    {
        $forceClose = $input->getOption('force-close');

        $output->writeln('Processing BpMessage open lots');

        try {
            // First, process orphaned CREATING lots (stuck lots)
            $orphanedStats = $this->bpMessageEmailModel->processOrphanedCreatingLots(5);

            if ($orphanedStats['processed'] > 0) {
                $output->writeln("{$orphanedStats['processed']} orphaned CREATING lot(s) found, {$orphanedStats['marked_failed']} marked as FAILED");
            }

            // Process regular message lots (SMS/WhatsApp/RCS)
            $messageStats = $this->bpMessageModel->processOpenLots($forceClose);

            // Process email lots
            $emailStats = $this->bpMessageEmailModel->processOpenLots($forceClose);

            // Combine statistics
            $totalStats = [
                'processed' => $messageStats['processed'] + $emailStats['processed'],
                'succeeded' => $messageStats['succeeded'] + $emailStats['succeeded'],
                'failed'    => $messageStats['failed'] + $emailStats['failed'],
            ];

            $output->writeln("{$totalStats['processed']} total lot(s) processed in batches");
            $output->writeln("{$totalStats['succeeded']} lot(s) succeeded");

            if ($totalStats['failed'] > 0) {
                $output->writeln("{$totalStats['failed']} lot(s) failed");
            }

            $output->writeln('');

            return $totalStats['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("Error processing lots: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }
}
