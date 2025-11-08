<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\EmailBundle\Entity\Email;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class CreateTestTemplateCommand extends Command
{
    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        parent::__construct();
        $this->em = $em;
    }

    protected function configure(): void
    {
        $this
            ->setName('mautic:bpmessage:create-test-template')
            ->setDescription('Create a test email template for BpMessage testing')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Creating BpMessage Test Email Template');

        // Create email template
        $email = new Email();
        $email->setName('BpMessage Test Template');
        $email->setSubject('Olá {contactfield=firstname}!');
        $email->setFromAddress('noreply@example.com');
        $email->setFromName('BpMessage Test');
        $email->setEmailType('template');
        $email->setIsPublished(true);
        $email->setCustomHtml('<html><body><h1>Olá {contactfield=firstname} {contactfield=lastname}!</h1><p>Este é um email de teste usando template.</p><p>Seu email é: {contactfield=email}</p><p>Enviado via BpMessage API</p></body></html>');

        $this->em->persist($email);
        $this->em->flush();

        $io->success("Template created successfully!");
        $io->table(
            ['ID', 'Name', 'Subject', 'Type'],
            [
                [
                    $email->getId(),
                    $email->getName(),
                    $email->getSubject(),
                    $email->getEmailType(),
                ]
            ]
        );

        $io->note("You can now use template ID {$email->getId()} for testing");

        return Command::SUCCESS;
    }
}
