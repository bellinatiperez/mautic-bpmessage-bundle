<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Form\Type;

use Mautic\LeadBundle\Form\Type\LeadFieldsType;
use Mautic\LeadBundle\Model\FieldModel;
use MauticPlugin\MauticBpMessageBundle\Service\RoutesService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Form type for BpMessage campaign action.
 */
class BpMessageActionType extends AbstractType
{
    public function __construct(
        private FieldModel $fieldModel,
        private RoutesService $routesService
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Service Type (API: 1=WhatsApp, 2=SMS, 4=RCS)
        $builder->add(
            'service_type',
            ChoiceType::class,
            [
                'label'   => 'mautic.bpmessage.form.service_type',
                'choices' => [
                    'mautic.bpmessage.service_type.whatsapp' => 1,
                    'mautic.bpmessage.service_type.sms'      => 2,
                    'mautic.bpmessage.service_type.rcs'      => 4,
                ],
                'attr' => [
                    'class'                        => 'form-control',
                    'data-bpmessage-routes-trigger' => 'true',
                ],
                'required'    => true,
                'empty_data'  => '1', // Default to WhatsApp (1) when empty
                'placeholder' => false, // Don't show "Choose an option"
            ]
        );

        // CRM ID - Required for GetRoutes API
        // Using TextType to preserve leading zeros and alphanumeric values (e.g., "01", "C0001")
        $builder->add(
            'crm_id',
            TextType::class,
            [
                'label'      => 'mautic.bpmessage.form.crm_id',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'                        => 'form-control',
                    'tooltip'                      => 'mautic.bpmessage.form.crm_id.tooltip',
                    'data-bpmessage-routes-trigger' => 'true',
                ],
                'required'    => true,
                'constraints' => [
                    new NotBlank(['message' => 'mautic.bpmessage.crm_id.notblank']),
                ],
            ]
        );

        // Book Business Foreign ID (Carteira) - Required for GetRoutes API
        // Using TextType to preserve leading zeros and alphanumeric values (e.g., "01", "00001", "C0001")
        $builder->add(
            'book_business_foreign_id',
            TextType::class,
            [
                'label'      => 'mautic.bpmessage.form.book_business_foreign_id',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'                        => 'form-control',
                    'tooltip'                      => 'mautic.bpmessage.form.book_business_foreign_id.tooltip',
                    'data-bpmessage-routes-trigger' => 'true',
                ],
                'required'    => true,
                'constraints' => [
                    new NotBlank(['message' => 'mautic.bpmessage.book_business_foreign_id.notblank']),
                ],
            ]
        );

        // Route Selection (ID Service Settings) - Populated dynamically via AJAX
        // The select will show route name but store idServiceSettings value
        $builder->add(
            'id_service_settings',
            ChoiceType::class,
            [
                'label'      => 'mautic.bpmessage.form.route',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'                        => 'form-control',
                    'tooltip'                      => 'mautic.bpmessage.form.route.tooltip',
                    'data-bpmessage-routes-select' => 'true',
                ],
                'choices'     => [], // Will be populated via AJAX
                'required'    => true,
                'placeholder' => 'mautic.bpmessage.form.route.placeholder',
                'constraints' => [
                    new NotBlank(['message' => 'mautic.bpmessage.route.notblank']),
                ],
            ]
        );

        // Hidden field to store the full route object (JSON) for display when editing
        // This avoids calling GetRoutes API just to show route info
        // Contains: id, label, provider, price, quota, available, useTemplate, idQuotaSettings
        $builder->add(
            'route_data',
            HiddenType::class,
            [
                'required' => false,
                'attr'     => [
                    'data-bpmessage-route-data' => 'true',
                ],
            ]
        );

        // Lot Configuration
        $builder->add(
            'lot_name',
            TextType::class,
            [
                'label'      => 'mautic.bpmessage.form.lot_name',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'   => 'form-control',
                    'tooltip' => 'mautic.bpmessage.form.lot_name.tooltip',
                ],
                'required' => false,
            ]
        );

        // Lot Data - Custom fields for CreateLot payload
        $builder->add(
            'lot_data',
            KeyValueListType::class,
            [
                'required' => false,
                'label'    => 'mautic.bpmessage.form.lot_data',
                'attr'     => [
                    'tooltip' => 'mautic.bpmessage.form.lot_data.tooltip',
                ],
            ]
        );

        // Set initial value for route select when editing existing action
        $routesService = $this->routesService;
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use ($routesService) {
            $data = $event->getData();
            $form = $event->getForm();

            if (is_array($data) && !empty($data['id_service_settings'])) {
                $initialValue = $data['id_service_settings'];

                // Get route_data - could be string (from DB) or array
                $routeDataRaw = $data['route_data'] ?? '';
                $routeDataJson = $routeDataRaw;
                $isStaleData = false;

                // If it's already an array (from form submission), convert to JSON string
                if (is_array($routeDataRaw)) {
                    $routeDataJson = json_encode($routeDataRaw);
                    $routeData = $routeDataRaw;
                } else {
                    // It's a string, try to decode it
                    $routeData = json_decode($routeDataRaw, true);
                }

                // Try to get route label from saved route_data
                // BUT only if route_data.id matches id_service_settings (to avoid stale data)
                $routeLabel = (string) $initialValue;
                if (is_array($routeData) && !empty($routeData['label'])) {
                    // Check if route_data matches current id_service_settings
                    $routeDataId = $routeData['id'] ?? null;
                    if ($routeDataId !== null && (int) $routeDataId === (int) $initialValue) {
                        $routeLabel = $routeData['label'];
                    } else {
                        // route_data is stale - clear it so JS can update it
                        $routeDataJson = '';
                        $isStaleData = true;
                    }
                } else {
                    // route_data is missing or invalid
                    $isStaleData = true;
                }

                // If route_data is stale/missing, try to fetch from API to get the correct label
                if ($isStaleData) {
                    $serviceType = (int) ($data['service_type'] ?? 1);
                    // Keep as strings to preserve leading zeros and alphanumeric values
                    $crmId = trim((string) ($data['crm_id'] ?? ''));
                    $bookBusinessForeignId = trim((string) ($data['book_business_foreign_id'] ?? ''));

                    if ('' !== $crmId && '' !== $bookBusinessForeignId) {
                        try {
                            $routes = $routesService->getRoutes($bookBusinessForeignId, $crmId, $serviceType);
                            foreach ($routes as $route) {
                                if (isset($route['idServiceSettings']) && (int) $route['idServiceSettings'] === (int) $initialValue) {
                                    // Found the matching route - build label and route_data
                                    $routeLabel = sprintf(
                                        '%s - %s (R$ %.2f)',
                                        $route['name'] ?? 'Unknown',
                                        $route['provider'] ?? '',
                                        $route['price'] ?? 0
                                    );
                                    // Build route_data JSON for the hidden field
                                    $newRouteData = [
                                        'id' => (int) $route['idServiceSettings'],
                                        'label' => $routeLabel,
                                        'provider' => $route['provider'] ?? '',
                                        'price' => (float) ($route['price'] ?? 0),
                                        'quota' => (int) ($route['quota'] ?? 0),
                                        'available' => (int) ($route['available'] ?? 0),
                                        'useTemplate' => (bool) ($route['useTemplate'] ?? false),
                                        'idQuotaSettings' => (int) ($route['idQuotaSettings'] ?? 0),
                                    ];
                                    $routeDataJson = json_encode($newRouteData);
                                    $data['route_data'] = $routeDataJson;
                                    $isStaleData = false; // No longer stale
                                    break;
                                }
                            }
                        } catch (\Exception $e) {
                            // API call failed - keep showing the ID as label
                            // JavaScript will try to load the routes
                        }
                    }
                }

                // If still stale after API attempt, clear it so JS can try
                if ($isStaleData) {
                    $data['route_data'] = '';
                }

                $event->setData($data);

                // Re-add the field with the initial value as a valid choice and data-attribute
                $form->add(
                    'id_service_settings',
                    ChoiceType::class,
                    [
                        'label'      => 'mautic.bpmessage.form.route',
                        'label_attr' => ['class' => 'control-label'],
                        'attr'       => [
                            'class'                        => 'form-control',
                            'tooltip'                      => 'mautic.bpmessage.form.route.tooltip',
                            'data-bpmessage-routes-select' => 'true',
                            'data-initial-value'           => $initialValue,
                            'data-initial-route'           => is_string($routeDataJson) ? $routeDataJson : '',
                        ],
                        // Use route label from saved data as the choice label
                        'choices'     => [$routeLabel => (int) $initialValue],
                        'data'        => (int) $initialValue,
                        'required'    => true,
                        'placeholder' => 'mautic.bpmessage.form.route.placeholder',
                        'constraints' => [
                            new NotBlank(['message' => 'mautic.bpmessage.route.notblank']),
                        ],
                    ]
                );
            }
        });

        // Phone Field - select contact field containing phone number(s)
        $builder->add(
            'phone_field',
            LeadFieldsType::class,
            [
                'label'      => 'mautic.bpmessage.form.phone_field',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'   => 'form-control',
                    'tooltip' => 'mautic.bpmessage.form.phone_field.tooltip',
                ],
                'required'            => true,
                'with_tags'           => false,
                'with_company_fields' => false,
            ]
        );

        // Phone Limit - limit number of dispatches for collection fields
        // Always visible, but only applies to collection type fields
        $builder->add(
            'phone_limit',
            IntegerType::class,
            [
                'label'      => 'mautic.bpmessage.form.phone_limit',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'       => 'form-control',
                    'tooltip'     => 'mautic.bpmessage.form.phone_limit.tooltip',
                    'placeholder' => '0',
                    'min'         => 0,
                ],
                'required' => false,
            ]
        );

        // Set default value for phone_field when creating new action
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $data = $event->getData();
            if (is_array($data) && empty($data['phone_field'])) {
                $data['phone_field'] = 'mobile';
                $event->setData($data);
            }
        });

        // Handle pre-selected route value (when submitting form)
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            $form = $event->getForm();

            // If id_service_settings has a value, we need to add it as a valid choice
            if (!empty($data['id_service_settings'])) {
                // Get route_data - could be string or array
                $routeDataRaw = $data['route_data'] ?? '';
                $routeDataJson = $routeDataRaw;

                // If it's already an array, convert to JSON string
                if (is_array($routeDataRaw)) {
                    $routeDataJson = json_encode($routeDataRaw);
                    $routeData = $routeDataRaw;
                } else {
                    // It's a string, try to decode it
                    $routeData = json_decode($routeDataRaw, true);
                }

                // Try to get route label from submitted route_data
                // BUT only if route_data.id matches id_service_settings (to avoid stale data)
                $routeLabel = (string) $data['id_service_settings'];
                if (is_array($routeData) && !empty($routeData['label'])) {
                    // Check if route_data matches current id_service_settings
                    $routeDataId = $routeData['id'] ?? null;
                    if ($routeDataId !== null && (int) $routeDataId === (int) $data['id_service_settings']) {
                        $routeLabel = $routeData['label'];
                    } else {
                        // route_data is stale - clear it
                        $routeDataJson = '';
                    }
                }

                $form->add(
                    'id_service_settings',
                    ChoiceType::class,
                    [
                        'label'      => 'mautic.bpmessage.form.route',
                        'label_attr' => ['class' => 'control-label'],
                        'attr'       => [
                            'class'                       => 'form-control',
                            'tooltip'                     => 'mautic.bpmessage.form.route.tooltip',
                            'data-bpmessage-routes-select' => 'true',
                            'data-initial-value'          => $data['id_service_settings'],
                            'data-initial-route'          => is_string($routeDataJson) ? $routeDataJson : '',
                        ],
                        'choices'     => [$routeLabel => (int) $data['id_service_settings']],
                        'required'    => true,
                        'placeholder' => 'mautic.bpmessage.form.route.placeholder',
                        'constraints' => [
                            new NotBlank(['message' => 'mautic.bpmessage.route.notblank']),
                        ],
                    ]
                );
            }
        });

        // Message Text (SMS/WhatsApp)
        $builder->add(
            'message_text',
            TextareaType::class,
            [
                'label'      => 'mautic.bpmessage.form.message_text',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'       => 'form-control',
                    'rows'        => 8,
                    'tooltip'     => 'mautic.bpmessage.form.message_text.tooltip',
                    'placeholder' => "Olá {contactfield=firstname},\n\nSua mensagem aqui...",
                ],
                'required' => false,
            ]
        );

        // Additional Data - Custom fields for message
        $builder->add(
            'additional_data',
            KeyValueListType::class,
            [
                'required' => false,
                'label'    => 'mautic.bpmessage.form.additional_data',
                'attr'     => [
                    'tooltip' => 'mautic.bpmessage.form.additional_data.tooltip',
                ],
            ]
        );

        // Message Variables - Custom variables array
        $builder->add(
            'message_variables',
            KeyValueListType::class,
            [
                'required' => false,
                'label'    => 'mautic.bpmessage.form.message_variables',
                'attr'     => [
                    'tooltip' => 'mautic.bpmessage.form.message_variables.tooltip',
                ],
            ]
        );

        // RCS Template
        $builder->add(
            'id_template',
            TextType::class,
            [
                'label'      => 'mautic.bpmessage.form.id_template',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'   => 'form-control',
                    'tooltip' => 'mautic.bpmessage.form.id_template.tooltip',
                ],
                'required' => false,
            ]
        );

        $builder->add(
            'control',
            ChoiceType::class,
            [
                'label'   => 'mautic.bpmessage.form.control',
                'choices' => [
                    'mautic.core.yes' => true,
                    'mautic.core.no'  => false,
                ],
                'attr'     => ['class' => 'form-control'],
                'data'     => true,
                'required' => false,
            ]
        );
    }

    public function getBlockPrefix(): string
    {
        return 'bpmessage_action';
    }
}
