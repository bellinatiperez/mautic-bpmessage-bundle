<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\EmailBundle\Entity\Email;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\MauticBpMessageBundle\Service\EmailMessageMapper;
use MauticPlugin\MauticBpMessageBundle\Service\EmailTemplateMessageMapper;
use MauticPlugin\MauticBpMessageBundle\Service\MessageMapper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class TestBpMessageActionsCommand extends Command
{
    private EntityManagerInterface $em;
    private MessageMapper $messageMapper;
    private EmailMessageMapper $emailMessageMapper;
    private EmailTemplateMessageMapper $emailTemplateMessageMapper;

    public function __construct(
        EntityManagerInterface $em,
        MessageMapper $messageMapper,
        EmailMessageMapper $emailMessageMapper,
        EmailTemplateMessageMapper $emailTemplateMessageMapper
    ) {
        parent::__construct();
        $this->em = $em;
        $this->messageMapper = $messageMapper;
        $this->emailMessageMapper = $emailMessageMapper;
        $this->emailTemplateMessageMapper = $emailTemplateMessageMapper;
    }

    protected function configure(): void
    {
        $this
            ->setName('mautic:bpmessage:test-actions')
            ->setDescription('Test BpMessage actions and show request payloads')
            ->addOption('contacts', 'c', InputOption::VALUE_OPTIONAL, 'Number of contacts to test', 3)
            ->addOption('action', 'a', InputOption::VALUE_OPTIONAL, 'Action to test (message|email|template|all)', 'all')
            ->addOption('template-id', 't', InputOption::VALUE_OPTIONAL, 'Email template ID for template action', null)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $contactsCount = (int) $input->getOption('contacts');
        $action = $input->getOption('action');
        $templateId = $input->getOption('template-id');

        $io->title('BpMessage Actions Test');

        // Get sample leads
        $leads = $this->em->getRepository(Lead::class)->findBy([], ['id' => 'DESC'], $contactsCount);

        if (empty($leads)) {
            $io->error('No contacts found in database');
            return Command::FAILURE;
        }

        $io->section("Testing with {$contactsCount} contacts");
        $io->table(['ID', 'Email', 'First Name', 'Last Name'], array_map(function (Lead $lead) {
            return [
                $lead->getId(),
                $lead->getEmail() ?? 'N/A',
                $lead->getFirstname() ?? 'N/A',
                $lead->getLastname() ?? 'N/A',
            ];
        }, $leads));

        // Create fake campaign for testing
        $campaign = new Campaign();
        $campaign->setName('Test Campaign');

        // Test different actions
        if ($action === 'all' || $action === 'message') {
            $this->testMessageAction($io, $leads, $campaign);
        }

        if ($action === 'all' || $action === 'email') {
            $this->testEmailAction($io, $leads, $campaign);
        }

        if ($action === 'all' || $action === 'template') {
            $this->testEmailTemplateAction($io, $leads, $campaign, $templateId);
        }

        $io->success('Test completed! Check logs at var/logs/mautic_dev.log for detailed HTTP requests');

        return Command::SUCCESS;
    }

    private function testMessageAction(SymfonyStyle $io, array $leads, Campaign $campaign): void
    {
        $io->section('1. Send BpMessage (SMS/WhatsApp/RCS)');

        $config = [
            'service_type' => 2, // WhatsApp
            'message_text' => 'Olá {contactfield=firstname}, tudo bem?',
            'id_quota_settings' => 123,
            'id_service_settings' => 456,
            'additional_data' => [
                'contract' => '{contactfield=contract_number}',
                'cpf' => '{contactfield=cpf}',
                'phone' => '{contactfield=mobile}',
            ],
        ];

        $io->writeln('<info>Configuration:</info>');
        $io->writeln(json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $io->newLine();

        $io->writeln('<info>Sample Payloads (first 2 contacts):</info>');
        foreach (array_slice($leads, 0, 2) as $index => $lead) {
            try {
                $payload = $this->messageMapper->mapLeadToMessage($lead, $config, $campaign);
                $io->writeln(sprintf('<comment>Contact #%d (ID: %d):</comment>', $index + 1, $lead->getId()));
                $io->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $io->newLine();
            } catch (\Exception $e) {
                $io->error("Failed to map contact {$lead->getId()}: " . $e->getMessage());
            }
        }
    }

    private function testEmailAction(SymfonyStyle $io, array $leads, Campaign $campaign): void
    {
        $io->section('2. Send BpMessage Email');

        $config = [
            'id_service_settings' => 789,
            'email_from' => 'noreply@example.com',
            'email_to' => '{contactfield=email}',
            'email_subject' => 'Hello {contactfield=firstname}!',
            'email_body' => '<html><body><h1>Hello {contactfield=firstname}!</h1><p>This is a test email.</p></body></html>',
            'additional_data' => [
                'contract' => '{contactfield=contract_number}',
                'cpfCnpjReceiver' => '{contactfield=cpf}',
            ],
        ];

        $io->writeln('<info>Configuration:</info>');
        $io->writeln(json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $io->newLine();

        $io->writeln('<info>Sample Payloads (first 2 contacts):</info>');
        foreach (array_slice($leads, 0, 2) as $index => $lead) {
            try {
                $payload = $this->emailMessageMapper->mapLeadToEmail($lead, $config, $campaign);
                $io->writeln(sprintf('<comment>Contact #%d (ID: %d):</comment>', $index + 1, $lead->getId()));
                // Truncate body for readability
                if (isset($payload['body']) && strlen($payload['body']) > 100) {
                    $payload['body'] = substr($payload['body'], 0, 100) . '... [truncated]';
                }
                $io->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $io->newLine();
            } catch (\Exception $e) {
                $io->error("Failed to map contact {$lead->getId()}: " . $e->getMessage());
            }
        }
    }

    private function testEmailTemplateAction(SymfonyStyle $io, array $leads, Campaign $campaign, ?string $templateId): void
    {
        $io->section('3. Send BpMessage Email Template');

        // Find a template
        if (!$templateId) {
            $template = $this->em->getRepository(Email::class)
                ->createQueryBuilder('e')
                ->where('e.emailType = :type')
                ->setParameter('type', 'template')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if (!$template) {
                $io->warning('No email template found. Create one in Mautic first or use --template-id option.');
                return;
            }

            $templateId = $template->getId();
        }

        $config = [
            'email_template' => $templateId,
            'id_service_settings' => 789,
            'email_from' => '', // Will use template default
            'email_to' => '', // Will use contact email
            'additional_data' => [
                'contract' => '{contactfield=contract_number}',
            ],
        ];

        $io->writeln(sprintf('<info>Using Template ID: %s</info>', $templateId));
        $io->writeln('<info>Configuration:</info>');
        $io->writeln(json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $io->newLine();

        $io->writeln('<info>Sample Payloads (first 2 contacts):</info>');
        foreach (array_slice($leads, 0, 2) as $index => $lead) {
            try {
                $payload = $this->emailTemplateMessageMapper->mapLeadToEmail($lead, $config, $campaign);
                $io->writeln(sprintf('<comment>Contact #%d (ID: %d):</comment>', $index + 1, $lead->getId()));
                // Truncate body for readability
                if (isset($payload['body']) && strlen($payload['body']) > 100) {
                    $payload['body'] = substr($payload['body'], 0, 100) . '... [truncated]';
                }
                $io->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $io->newLine();
            } catch (\Exception $e) {
                $io->error("Failed to map contact {$lead->getId()}: " . $e->getMessage());
            }
        }
    }
}
