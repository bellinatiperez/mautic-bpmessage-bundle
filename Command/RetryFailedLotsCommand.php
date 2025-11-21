<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Command;

use Doctrine\ORM\EntityManager;
use MauticPlugin\MauticBpMessageBundle\Entity\BpMessageLot;
use MauticPlugin\MauticBpMessageBundle\Entity\BpMessageQueue;
use MauticPlugin\MauticBpMessageBundle\Http\BpMessageClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to retry creating lots that failed during API call
 * This ensures no contacts are lost even when BpMessage API is unavailable
 */
class RetryFailedLotsCommand extends Command
{
    private EntityManager $em;
    private BpMessageClient $client;
    private LoggerInterface $logger;

    public function __construct(
        EntityManager $entityManager,
        BpMessageClient $client,
        LoggerInterface $logger
    ) {
        $this->em = $entityManager;
        $this->client = $client;
        $this->logger = $logger;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mautic:bpmessage:retry-failed-lots')
            ->setDescription('Retry creating lots that failed during API call')
            ->setHelp(
                <<<'EOT'
The <info>%command.name%</info> command retries creating lots that failed during the initial API call.

This ensures no contacts are lost when the BpMessage API is temporarily unavailable.

<info>php %command.full_name%</info>

Retry specific lot:
<info>php %command.full_name% --lot-id=123</info>

Limit number of lots to retry:
<info>php %command.full_name% --limit=10</info>
EOT
            )
            ->addOption(
                'lot-id',
                'l',
                InputOption::VALUE_REQUIRED,
                'Retry a specific lot by ID'
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum number of lots to process',
                '50'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('BpMessage - Retry Failed Lot Creation');

        $lotId = $input->getOption('lot-id');
        $limit = (int) $input->getOption('limit');

        if ($lotId) {
            return $this->retrySpecificLot((int) $lotId, $io);
        }

        return $this->retryFailedLots($limit, $io);
    }

    private function retrySpecificLot(int $lotId, SymfonyStyle $io): int
    {
        $io->section("Retrying lot #{$lotId}");

        $lot = $this->em->find(BpMessageLot::class, $lotId);

        if (null === $lot) {
            $io->error("Lot #{$lotId} not found");
            return Command::FAILURE;
        }

        if ($lot->getStatus() !== 'FAILED_CREATION') {
            $io->warning("Lot #{$lotId} status is {$lot->getStatus()}, not FAILED_CREATION");
            return Command::FAILURE;
        }

        $success = $this->retryLotCreation($lot, $io);

        if ($success) {
            $io->success("Lot #{$lotId} created successfully in BpMessage");
            return Command::SUCCESS;
        }

        $io->error("Failed to create lot #{$lotId} in BpMessage");
        return Command::FAILURE;
    }

    private function retryFailedLots(int $limit, SymfonyStyle $io): int
    {
        $io->section('Finding failed lots');

        // Find lots with FAILED_CREATION status
        $qb = $this->em->createQueryBuilder();
        $qb->select('l')
            ->from(BpMessageLot::class, 'l')
            ->where('l.status = :status')
            ->setParameter('status', 'FAILED_CREATION')
            ->orderBy('l.createdAt', 'ASC')
            ->setMaxResults($limit);

        $failedLots = $qb->getQuery()->getResult();

        if (empty($failedLots)) {
            $io->info('No failed lots found');
            return Command::SUCCESS;
        }

        $io->text("Found " . count($failedLots) . " failed lot(s)");

        $stats = [
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
        ];

        foreach ($failedLots as $lot) {
            ++$stats['processed'];

            $io->text("Processing lot #{$lot->getId()} - {$lot->getName()}");

            $success = $this->retryLotCreation($lot, $io);

            if ($success) {
                ++$stats['succeeded'];
            } else {
                ++$stats['failed'];
            }
        }

        $io->table(
            ['Metric', 'Count'],
            [
                ['Lots Processed', $stats['processed']],
                ['Successfully Created', $stats['succeeded']],
                ['Still Failed', $stats['failed']],
            ]
        );

        if ($stats['failed'] > 0) {
            $io->warning("Some lots still failed. Check error messages and try again later.");
            return Command::FAILURE;
        }

        $io->success("All failed lots have been successfully created in BpMessage");
        return Command::SUCCESS;
    }

    private function retryLotCreation(BpMessageLot $lot, SymfonyStyle $io): bool
    {
        try {
            // Prepare lot data
            $lotData = [
                'name' => $lot->getName(),
                'startDate' => $lot->getStartDate()->format('c'),
                'endDate' => $lot->getEndDate()->format('c'),
                'user' => 'system',
                'idQuotaSettings' => $lot->getIdQuotaSettings(),
                'idServiceSettings' => $lot->getIdServiceSettings(),
            ];

            if (null !== $lot->getImageUrl()) {
                $lotData['imageUrl'] = $lot->getImageUrl();
            }

            if (null !== $lot->getImageName()) {
                $lotData['imageName'] = $lot->getImageName();
            }

            // Call BpMessage API
            $this->client->setBaseUrl($lot->getApiBaseUrl());
            $result = $this->client->createLot($lotData);

            if (!$result['success']) {
                $io->error("  API error: {$result['error']}");

                // Update error message
                $lot->setErrorMessage($result['error']);
                $this->em->flush();

                return false;
            }

            // Success! Update lot
            $lot->setExternalLotId((string) $result['idLot']);
            $lot->setStatus('OPEN');
            $lot->setErrorMessage(null);
            $this->em->flush();

            $io->success("  Lot created with external ID: {$result['idLot']}");

            // Now process PENDING messages for this lot
            $this->processPendingMessages($lot, $io);

            return true;
        } catch (\Exception $e) {
            $io->error("  Exception: {$e->getMessage()}");

            // Update error message
            $lot->setErrorMessage('Exception: ' . $e->getMessage());
            $this->em->flush();

            return false;
        }
    }

    private function processPendingMessages(BpMessageLot $lot, SymfonyStyle $io): void
    {
        // Get count of PENDING messages
        $qb = $this->em->createQueryBuilder();
        $qb->select('COUNT(q.id)')
            ->from(BpMessageQueue::class, 'q')
            ->where('q.lot = :lot')
            ->andWhere('q.status = :status')
            ->setParameter('lot', $lot)
            ->setParameter('status', 'PENDING');

        $count = (int) $qb->getQuery()->getSingleScalarResult();

        if ($count > 0) {
            $io->text("  Found {$count} pending message(s) for this lot");
            $io->text("  These will be processed on the next run of mautic:bpmessage:process");
        }
    }
}
