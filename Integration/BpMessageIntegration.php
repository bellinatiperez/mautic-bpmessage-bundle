<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Integration;

use Doctrine\ORM\EntityManager;
use Mautic\CoreBundle\Helper\CacheStorageHelper;
use Mautic\CoreBundle\Helper\EncryptionHelper;
use Mautic\CoreBundle\Helper\PathsHelper;
use Mautic\CoreBundle\Model\NotificationModel;
use Mautic\LeadBundle\Field\FieldsWithUniqueIdentifier;
use Mautic\LeadBundle\Model\CompanyModel;
use Mautic\LeadBundle\Model\DoNotContact as DoNotContactModel;
use Mautic\LeadBundle\Model\FieldModel;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\PluginBundle\Integration\AbstractIntegration;
use Mautic\PluginBundle\Model\IntegrationEntityModel;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * BpMessage Integration for plugin configuration
 */
class BpMessageIntegration extends AbstractIntegration
{
    /**
     * Constructor with all required dependencies
     */
    public function __construct(
        EventDispatcherInterface $dispatcher,
        CacheStorageHelper $cacheStorageHelper,
        EntityManager $em,
        RequestStack $requestStack,
        RouterInterface $router,
        TranslatorInterface $translator,
        LoggerInterface $logger,
        EncryptionHelper $encryptionHelper,
        LeadModel $leadModel,
        CompanyModel $companyModel,
        PathsHelper $pathsHelper,
        NotificationModel $notificationModel,
        FieldModel $fieldModel,
        IntegrationEntityModel $integrationEntityModel,
        DoNotContactModel $doNotContact,
        FieldsWithUniqueIdentifier $fieldsWithUniqueIdentifier,
    ) {
        parent::__construct(
            $dispatcher,
            $cacheStorageHelper,
            $em,
            $requestStack,
            $router,
            $translator,
            $logger,
            $encryptionHelper,
            $leadModel,
            $companyModel,
            $pathsHelper,
            $notificationModel,
            $fieldModel,
            $integrationEntityModel,
            $doNotContact,
            $fieldsWithUniqueIdentifier,
        );
    }

    public function getName(): string
    {
        return 'BpMessage';
    }

    public function getDisplayName(): string
    {
        return 'BpMessage';
    }

    public function getIcon(): string
    {
        return 'plugins/MauticBpMessageBundle/Assets/img/bpmessage.png';
    }

    public function getAuthenticationType(): string
    {
        return 'none';
    }

    /**
     * @return array<string>
     */
    public function getSupportedFeatures(): array
    {
        return ['api_base_url'];
    }

    /**
     * @return array<string, string>
     */
    public function getRequiredKeyFields(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getFormSettings(): array
    {
        return [
            'requires_callback'      => false,
            'requires_authorization' => false,
        ];
    }

    /**
     * Add configuration fields
     *
     * @param \Mautic\PluginBundle\Integration\Form|FormBuilder $builder
     * @param array $data
     * @param string $formArea
     */
    public function appendToForm(&$builder, $data, $formArea): void
    {
        if ('features' === $formArea) {
            $builder->add(
                'api_base_url',
                TextType::class,
                [
                    'label' => 'mautic.bpmessage.integration.api_base_url',
                    'label_attr' => ['class' => 'control-label'],
                    'required' => true,
                    'data' => $data['api_base_url'] ?? 'https://api.bpmessage.com.br',
                    'attr' => [
                        'class' => 'form-control',
                        'tooltip' => 'mautic.bpmessage.integration.api_base_url.tooltip',
                        'placeholder' => 'https://api.bpmessage.com.br',
                    ],
                ]
            );

            $builder->add(
                'default_batch_size',
                \Symfony\Component\Form\Extension\Core\Type\IntegerType::class,
                [
                    'label' => 'mautic.bpmessage.integration.default_batch_size',
                    'label_attr' => ['class' => 'control-label'],
                    'required' => false,
                    'data' => $data['default_batch_size'] ?? 1000,
                    'attr' => [
                        'class' => 'form-control',
                        'tooltip' => 'mautic.bpmessage.integration.default_batch_size.tooltip',
                        'min' => 1,
                        'max' => 5000,
                    ],
                ]
            );

            $builder->add(
                'default_time_window',
                \Symfony\Component\Form\Extension\Core\Type\IntegerType::class,
                [
                    'label' => 'mautic.bpmessage.integration.default_time_window',
                    'label_attr' => ['class' => 'control-label'],
                    'required' => false,
                    'data' => $data['default_time_window'] ?? 300,
                    'attr' => [
                        'class' => 'form-control',
                        'tooltip' => 'mautic.bpmessage.integration.default_time_window.tooltip',
                        'min' => 60,
                        'max' => 3600,
                    ],
                ]
            );
        }
    }

    /**
     * Get API Base URL from integration settings
     *
     * @return string|null
     */
    public function getApiBaseUrl(): ?string
    {
        $featureSettings = $this->getIntegrationSettings()->getFeatureSettings();

        return $featureSettings['api_base_url'] ?? null;
    }

    /**
     * Get default batch size from integration settings
     *
     * @return int
     */
    public function getDefaultBatchSize(): int
    {
        $featureSettings = $this->getIntegrationSettings()->getFeatureSettings();

        return (int) ($featureSettings['default_batch_size'] ?? 1000);
    }

    /**
     * Get default time window from integration settings
     *
     * @return int
     */
    public function getDefaultTimeWindow(): int
    {
        $featureSettings = $this->getIntegrationSettings()->getFeatureSettings();

        return (int) ($featureSettings['default_time_window'] ?? 300);
    }
}
