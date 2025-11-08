<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Command;

use MauticPlugin\MauticBpMessageBundle\Model\BpMessageModel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to cleanup old BpMessage lots and messages
 */
class CleanupBpMessageCommand extends Command
{
    private BpMessageModel $bpMessageModel;

    public function __construct(BpMessageModel $bpMessageModel)
    {
        $this->bpMessageModel = $bpMessageModel;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mautic:bpmessage:cleanup')
            ->setDescription('Cleanup old BpMessage lots and messages')
            ->setHelp(
                <<<'EOT'
The <info>%command.name%</info> command removes old finished lots and their messages from the database.

<info>php %command.full_name%</info>

Delete lots older than 30 days (default):
<info>php %command.full_name%</info>

Delete lots older than 60 days:
<info>php %command.full_name% --days=60</info>

Dry run (preview what would be deleted):
<info>php %command.full_name% --dry-run</info>
EOT
            )
            ->addOption(
                'days',
                'd',
                InputOption::VALUE_REQUIRED,
                'Delete lots older than this many days',
                '30'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Preview what would be deleted without actually deleting'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $days = (int) $input->getOption('days');
        $dryRun = $input->getOption('dry-run');

        $io->title('BpMessage Cleanup');

        if ($dryRun) {
            $io->warning('DRY RUN MODE - No data will be deleted');
        }

        $io->text("Deleting lots and messages older than {$days} days");

        try {
            if ($dryRun) {
                $io->info('Dry run mode - skipping actual deletion');
                $io->note('Run without --dry-run to perform actual cleanup');
                return Command::SUCCESS;
            }

            $result = $this->bpMessageModel->cleanup($days);

            $io->table(
                ['Type', 'Deleted'],
                [
                    ['Lots', $result['lots_deleted']],
                ]
            );

            if ($result['lots_deleted'] > 0) {
                $io->success("Cleanup completed - deleted {$result['lots_deleted']} lots");
            } else {
                $io->info('No old lots found to delete');
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error("Error during cleanup: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
