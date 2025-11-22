<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Command;

use Doctrine\ORM\EntityManager;
use MauticPlugin\MauticBpMessageBundle\Entity\BpMessageLot;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to list lots with errors.
 */
class ListFailedLotsCommand extends Command
{
    private EntityManager $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mautic:bpmessage:list-failed-lots')
            ->setDescription('List BpMessage lots with errors')
            ->setHelp(
                <<<'EOT'
The <info>%command.name%</info> command lists all BpMessage lots that have errors.

<info>php %command.full_name%</info>

Show more details:
<info>php %command.full_name% --verbose</info>

Limit results:
<info>php %command.full_name% --limit=10</info>
EOT
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Maximum number of lots to display',
                '20'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $limit = (int) $input->getOption('limit');

        $io->title('BpMessage Lots with Errors');

        // Query lots with FAILED status or non-null error_message
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('l')
            ->from(BpMessageLot::class, 'l')
            ->where($qb->expr()->orX(
                $qb->expr()->eq('l.status', ':failedStatus'),
                $qb->expr()->eq('l.status', ':failedCreationStatus'),
                $qb->expr()->isNotNull('l.errorMessage')
            ))
            ->setParameter('failedStatus', 'FAILED')
            ->setParameter('failedCreationStatus', 'FAILED_CREATION')
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit);

        $lots = $qb->getQuery()->getResult();

        if (empty($lots)) {
            $io->success('No failed lots found!');

            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d lot(s) with errors (showing up to %d)', count($lots), $limit));
        $io->newLine();

        $tableData = [];

        /** @var BpMessageLot $lot */
        foreach ($lots as $lot) {
            $serviceTypeName = match ($lot->getServiceType()) {
                1       => 'SMS',
                2       => 'WhatsApp',
                3       => 'RCS',
                default => 'Unknown',
            };

            $errorMessage = $lot->getErrorMessage() ?? 'No error message';

            // Truncate error message if too long for table display
            if (strlen($errorMessage) > 100) {
                $errorMessage = substr($errorMessage, 0, 97).'...';
            }

            $tableData[] = [
                $lot->getId(),
                $lot->getName(),
                $lot->getStatus(),
                $serviceTypeName,
                $lot->getMessagesCount(),
                $lot->getCreatedAt()->format('Y-m-d H:i:s'),
                $errorMessage,
            ];
        }

        $io->table(
            ['ID', 'Name', 'Status', 'Type', 'Messages', 'Created At', 'Error'],
            $tableData
        );

        // Show detailed errors in verbose mode
        if ($output->isVerbose()) {
            $io->section('Detailed Errors');

            foreach ($lots as $lot) {
                $io->writeln("<fg=cyan>Lot #{$lot->getId()}: {$lot->getName()}</>");
                $io->writeln("<fg=yellow>Status:</> {$lot->getStatus()}");
                $io->writeln('<fg=yellow>Error:</>');
                $io->writeln("<fg=red>  {$lot->getErrorMessage()}</>");
                $io->newLine();
            }
        }

        $io->note('To see full error details, run this command with --verbose (-v) flag');
        $io->note('To retry a failed lot, run: php bin/console mautic:bpmessage:process --lot-id=<ID>');

        return Command::SUCCESS;
    }
}
