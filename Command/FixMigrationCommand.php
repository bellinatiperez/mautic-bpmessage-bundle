<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Command;

use Doctrine\DBAL\Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\CoreParametersHelper;

/**
 * Command to fix incomplete migrations in production
 */
class FixMigrationCommand extends Command
{
    protected static $defaultName = 'mautic:bpmessage:fix-migration';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CoreParametersHelper $coreParams,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Fix incomplete BpMessage migrations')
            ->setHelp('This command checks and fixes missing tables from BpMessage migrations')
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be done without executing'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force execution even if tables already exist'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isDryRun = $input->getOption('dry-run');
        $force = $input->getOption('force');

        $io->title('BpMessage Migration Fixer');

        $tablePrefix = $this->coreParams->get('db_table_prefix') ?? '';
        $connection = $this->entityManager->getConnection();

        try {
            $schema = $connection->createSchemaManager()->introspectSchema();

            $lotTable = $tablePrefix . 'bpmessage_lot';
            $queueTable = $tablePrefix . 'bpmessage_queue';
            $leadsTable = $tablePrefix . 'leads';

            // Check current state
            $io->section('Checking current state');

            $lotExists = $schema->hasTable($lotTable);
            $queueExists = $schema->hasTable($queueTable);
            $leadsExists = $schema->hasTable($leadsTable);

            $io->table(
                ['Table', 'Status'],
                [
                    [$lotTable, $lotExists ? '✓ Exists' : '✗ Missing'],
                    [$queueTable, $queueExists ? '✓ Exists' : '✗ Missing'],
                    [$leadsTable, $leadsExists ? '✓ Exists' : '✗ Missing'],
                ]
            );

            if (!$leadsExists) {
                $io->error("The '{$leadsTable}' table does not exist. Cannot create foreign key.");
                return Command::FAILURE;
            }

            if ($queueExists && !$force) {
                $io->success("The '{$queueTable}' table already exists. Nothing to do.");
                $io->note('Use --force to recreate the table (this will delete existing data!)');
                return Command::SUCCESS;
            }

            if (!$lotExists) {
                $io->error("The '{$lotTable}' table does not exist. Run migrations first.");
                return Command::FAILURE;
            }

            // Get the ID column type from bpmessage_lot
            $lotTableObj = $schema->getTable($lotTable);
            $idColumn = $lotTableObj->getColumn('id');
            $idType = $idColumn->getType()->getName();
            $isUnsigned = $idColumn->getUnsigned();

            $io->writeln("Detected ID type in {$lotTable}: {$idType}" . ($isUnsigned ? ' UNSIGNED' : ''));

            // Get the ID column type from leads
            $leadsTableObj = $schema->getTable($leadsTable);
            $leadsIdColumn = $leadsTableObj->getColumn('id');
            $leadsIdType = $leadsIdColumn->getType()->getName();
            $leadsIsUnsigned = $leadsIdColumn->getUnsigned();

            $io->writeln("Detected ID type in {$leadsTable}: {$leadsIdType}" . ($leadsIsUnsigned ? ' UNSIGNED' : ''));

            // Determine the correct SQL type
            $lotIdSqlType = $this->getSqlType($idType, $isUnsigned);
            $leadsIdSqlType = $this->getSqlType($leadsIdType, $leadsIsUnsigned);

            $io->section('Preparing to create bpmessage_queue table');

            if ($queueExists && $force) {
                $io->warning("This will DROP the existing '{$queueTable}' table and all its data!");
                if (!$io->confirm('Are you sure you want to continue?', false)) {
                    $io->info('Operation cancelled.');
                    return Command::SUCCESS;
                }
            }

            $createTableSql = $this->buildCreateTableSql($queueTable, $lotTable, $leadsTable, $lotIdSqlType, $leadsIdSqlType);

            if ($isDryRun) {
                $io->note('DRY RUN MODE - No changes will be made');
                $io->section('SQL that would be executed:');

                if ($queueExists && $force) {
                    $io->writeln("DROP TABLE IF EXISTS `{$queueTable}`;");
                    $io->writeln('');
                }

                $io->writeln($createTableSql);
                return Command::SUCCESS;
            }

            // Execute the migration
            $io->section('Executing migration');

            if ($queueExists && $force) {
                $io->writeln("Dropping existing table '{$queueTable}'...");
                $connection->executeStatement("DROP TABLE IF EXISTS `{$queueTable}`");
                $io->writeln('✓ Table dropped');
            }

            $io->writeln("Creating table '{$queueTable}'...");
            $connection->executeStatement($createTableSql);
            $io->writeln('✓ Table created successfully');

            // Verify the table was created
            $schema = $connection->createSchemaManager()->introspectSchema();
            if ($schema->hasTable($queueTable)) {
                $io->success("Migration completed successfully! The '{$queueTable}' table is now ready.");

                // Show table structure
                $table = $schema->getTable($queueTable);
                $columns = [];
                foreach ($table->getColumns() as $column) {
                    $columns[] = [
                        $column->getName(),
                        $column->getType()->getName(),
                        $column->getNotnull() ? 'NOT NULL' : 'NULL',
                        $column->getDefault() !== null ? $column->getDefault() : '',
                    ];
                }

                $io->table(['Column', 'Type', 'Null', 'Default'], $columns);

                return Command::SUCCESS;
            } else {
                $io->error("Table creation reported success but table is still missing!");
                return Command::FAILURE;
            }

        } catch (Exception $e) {
            $io->error('Migration failed: ' . $e->getMessage());
            $io->writeln('');
            $io->section('Full error details:');
            $io->writeln($e->getTraceAsString());
            return Command::FAILURE;
        }
    }

    private function getSqlType(string $doctrineType, bool $unsigned): string
    {
        $type = match($doctrineType) {
            'integer', 'int' => 'INT',
            'bigint' => 'BIGINT',
            'smallint' => 'SMALLINT',
            default => 'INT',
        };

        return $type . ($unsigned ? ' UNSIGNED' : '');
    }

    private function buildCreateTableSql(
        string $queueTable,
        string $lotTable,
        string $leadsTable,
        string $lotIdType,
        string $leadsIdType
    ): string {
        return "
            CREATE TABLE `{$queueTable}` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `lot_id` {$lotIdType} NOT NULL,
                `lead_id` {$leadsIdType} NOT NULL,
                `payload_json` LONGTEXT NOT NULL,
                `status` VARCHAR(191) DEFAULT NULL,
                `retry_count` SMALLINT(6) NOT NULL DEFAULT 0,
                `error_message` LONGTEXT DEFAULT NULL,
                `created_at` DATETIME NOT NULL,
                `sent_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                INDEX `idx_lot_status` (`lot_id`, `status`),
                INDEX `idx_created_at` (`created_at`),
                INDEX `idx_status` (`status`),
                CONSTRAINT `fk_bpmessage_queue_lot`
                    FOREIGN KEY (`lot_id`)
                    REFERENCES `{$lotTable}` (`id`)
                    ON DELETE CASCADE,
                CONSTRAINT `fk_bpmessage_queue_lead`
                    FOREIGN KEY (`lead_id`)
                    REFERENCES `{$leadsTable}` (`id`)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
    }
}
