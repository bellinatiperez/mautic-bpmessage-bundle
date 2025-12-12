<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Form\Type;

use Mautic\LeadBundle\Form\Type\LeadFieldsType;
use Mautic\LeadBundle\Model\FieldModel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
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
        private FieldModel $fieldModel
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Service Type (API: 1=WhatsApp, 2=SMS, 3=Email, 4=RCS)
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
                'attr'        => ['class' => 'form-control'],
                'required'    => true,
                'empty_data'  => '1', // Default to WhatsApp (1) when empty
                'placeholder' => false, // Don't show "Choose an option"
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

        // Route Settings
        $builder->add(
            'id_quota_settings',
            IntegerType::class,
            [
                'label'      => 'mautic.bpmessage.form.id_quota_settings',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'   => 'form-control',
                    'tooltip' => 'mautic.bpmessage.form.id_quota_settings.tooltip',
                ],
                'required'    => true,
                'constraints' => [
                    new NotBlank(['message' => 'mautic.bpmessage.id_quota_settings.notblank']),
                ],
            ]
        );

        $builder->add(
            'id_service_settings',
            IntegerType::class,
            [
                'label'      => 'mautic.bpmessage.form.id_service_settings',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'   => 'form-control',
                    'tooltip' => 'mautic.bpmessage.form.id_service_settings.tooltip',
                ],
                'required'    => true,
                'constraints' => [
                    new NotBlank(['message' => 'mautic.bpmessage.id_service_settings.notblank']),
                ],
            ]
        );

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

        // Set default value for phone_field when creating new action
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $data = $event->getData();
            if (is_array($data) && empty($data['phone_field'])) {
                $data['phone_field'] = 'mobile';
                $event->setData($data);
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
