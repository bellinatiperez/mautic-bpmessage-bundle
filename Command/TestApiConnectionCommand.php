<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Command;

use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\MauticBpMessageBundle\Http\BpMessageClient;
use MauticPlugin\MauticBpMessageBundle\Integration\BpMessageIntegration;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to test BpMessage API connection and operations
 */
class TestApiConnectionCommand extends Command
{
    private BpMessageClient $client;
    private LoggerInterface $logger;
    private IntegrationHelper $integrationHelper;

    public function __construct(
        BpMessageClient $client,
        LoggerInterface $logger,
        IntegrationHelper $integrationHelper
    ) {
        $this->client = $client;
        $this->logger = $logger;
        $this->integrationHelper = $integrationHelper;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mautic:bpmessage:test-api')
            ->setDescription('Test BpMessage API connection and operations')
            ->setHelp(
                <<<'EOT'
The <info>%command.name%</info> command tests the BpMessage API connection and performs real API calls.

<info>php %command.full_name%</info>

Test full flow (create lot, add messages, finish lot):
<info>php %command.full_name% --full-test</info>

Test with custom configuration:
<info>php %command.full_name% --id-quota=123 --id-service=456</info>

Test connection only:
<info>php %command.full_name% --connection-only</info>
EOT
            )
            ->addOption(
                'full-test',
                'f',
                InputOption::VALUE_NONE,
                'Perform full test (create lot, add messages, finish lot)'
            )
            ->addOption(
                'connection-only',
                'c',
                InputOption::VALUE_NONE,
                'Test connection only (no lot creation)'
            )
            ->addOption(
                'id-quota',
                null,
                InputOption::VALUE_REQUIRED,
                'ID Quota Settings to use for test'
            )
            ->addOption(
                'id-service',
                null,
                InputOption::VALUE_REQUIRED,
                'ID Service Settings to use for test'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('BpMessage API Connection Test');

        // Get integration settings
        /** @var BpMessageIntegration|null $integration */
        $integration = $this->integrationHelper->getIntegrationObject('BpMessage');

        if (!$integration) {
            $io->error('BpMessage integration not found. Please configure it in Settings > Plugins.');
            return Command::FAILURE;
        }

        $settings = $integration->getIntegrationSettings();

        if (!$settings || !$settings->getIsPublished()) {
            $io->error('BpMessage integration is not published. Please enable it in Settings > Plugins.');
            return Command::FAILURE;
        }

        $apiUrl = $integration->getApiBaseUrl();

        if (!$apiUrl) {
            $io->error('API Base URL not configured. Please configure it in the integration settings.');
            return Command::FAILURE;
        }

        $io->section('Integration Configuration');
        $io->table(
            ['Setting', 'Value'],
            [
                ['API Base URL', $apiUrl],
                ['Default Batch Size', $integration->getDefaultBatchSize()],
                ['Default Time Window', $integration->getDefaultTimeWindow() . ' seconds'],
            ]
        );

        $this->client->setBaseUrl($apiUrl);

        // Test connection
        if ($input->getOption('connection-only')) {
            return $this->testConnection($io);
        }

        // Full test
        if ($input->getOption('full-test')) {
            $idQuota = $input->getOption('id-quota');
            $idService = $input->getOption('id-service');

            if (!$idQuota || !$idService) {
                $io->error('Please provide --id-quota and --id-service for full test');
                return Command::FAILURE;
            }

            return $this->testFullFlow($io, (int) $idQuota, (int) $idService);
        }

        // Default: just test connection
        return $this->testConnection($io);
    }

    private function testConnection(SymfonyStyle $io): int
    {
        $io->section('Testing Connection');

        $io->writeln('Making test request to API...');

        $result = $this->client->testConnection();

        if ($result['success']) {
            $io->success('✓ Connection successful!');
            return Command::SUCCESS;
        }

        $io->error('✗ Connection failed: ' . $result['error']);
        return Command::FAILURE;
    }

    private function testFullFlow(SymfonyStyle $io, int $idQuota, int $idService): int
    {
        $io->section('Testing Full Flow (Create → Add Messages → Finish)');

        // Step 1: Create Lot
        $io->writeln('<info>Step 1: Creating lot...</info>');

        $lotData = [
            'name' => 'Test Lot - ' . date('Y-m-d H:i:s'),
            'startDate' => (new \DateTime())->format('c'),
            'endDate' => (new \DateTime())->format('c'),
            'user' => 'system',
            'idQuotaSettings' => $idQuota,
            'idServiceSettings' => $idService,
        ];

        $io->writeln('<comment>Lot Data:</comment>');
        $io->writeln(json_encode($lotData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $io->newLine();

        $createResult = $this->client->createLot($lotData);

        if (!$createResult['success']) {
            $io->error('✗ Failed to create lot: ' . $createResult['error']);
            return Command::FAILURE;
        }

        $idLot = $createResult['idLot'];
        $io->success("✓ Lot created successfully! ID: {$idLot}");
        $io->newLine();

        // Step 2: Add Messages
        $io->writeln('<info>Step 2: Adding test messages...</info>');

        $messages = [
            [
                'control' => true,
                'metaData' => json_encode([
                    'source' => 'mautic_test',
                    'timestamp' => time(),
                ]),
                'idForeignBookBusiness' => '11002',
                'contactName' => 'Test Contact 1',
                'idServiceType' => 2, // WhatsApp
                'text' => 'Test message 1 - ' . date('H:i:s'),
                'contract' => 'TEST001',
                'cpfCnpjReceiver' => '12345678901',
                'areaCode' => '48',
                'phone' => '999999999',
            ],
            [
                'control' => true,
                'metaData' => json_encode([
                    'source' => 'mautic_test',
                    'timestamp' => time(),
                ]),
                'idForeignBookBusiness' => '11002',
                'contactName' => 'Test Contact 2',
                'idServiceType' => 2, // WhatsApp
                'text' => 'Test message 2 - ' . date('H:i:s'),
                'contract' => 'TEST002',
                'cpfCnpjReceiver' => '98765432109',
                'areaCode' => '11',
                'phone' => '888888888',
            ],
        ];

        $io->writeln('<comment>Messages:</comment>');
        $io->writeln(json_encode($messages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $io->newLine();

        $addResult = $this->client->addMessagesToLot($idLot, $messages);

        if (!$addResult['success']) {
            $io->error('✗ Failed to add messages: ' . $addResult['error']);
            $io->warning("Lot {$idLot} was created but messages were not added. You may need to manually clean it up.");
            return Command::FAILURE;
        }

        $io->success('✓ Messages added successfully!');
        $io->newLine();

        // Step 3: Finish Lot
        $io->writeln('<info>Step 3: Finishing lot...</info>');

        $finishResult = $this->client->finishLot($idLot);

        if (!$finishResult['success']) {
            $io->error('✗ Failed to finish lot: ' . $finishResult['error']);
            return Command::FAILURE;
        }

        $io->success("✓ Lot finished successfully!");
        $io->newLine();

        $io->success([
            'Full test completed successfully!',
            "Lot ID: {$idLot}",
            'Check your BpMessage dashboard to verify the messages.',
        ]);

        return Command::SUCCESS;
    }
}
