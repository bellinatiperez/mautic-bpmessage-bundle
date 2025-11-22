<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Form\Type;

use Mautic\EmailBundle\Form\Type\EmailListType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Form type for BpMessage email template campaign action.
 */
class BpMessageEmailTemplateActionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
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

        // Lot Data - Custom fields for CreateEmailLot payload
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

        // Service Settings
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

        // Optional CRM fields
        $builder->add(
            'crm_id',
            IntegerType::class,
            [
                'required'   => true,
                'label'      => 'mautic.bpmessage.form.crm_id',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'   => 'form-control',
                    'tooltip' => 'mautic.bpmessage.form.crm_id.tooltip',
                ],
                'required' => false,
            ]
        );

        $builder->add(
            'book_business_foreign_id',
            TextType::class,
            [
                'required'   => true,
                'label'      => 'mautic.bpmessage.form.book_business_foreign_id',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'   => 'form-control',
                    'tooltip' => 'mautic.bpmessage.form.book_business_foreign_id.tooltip',
                ],
                'required' => false,
            ]
        );

        $builder->add(
            'step_foreign_id',
            TextType::class,
            [
                'label'      => 'mautic.bpmessage.form.step_foreign_id',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'   => 'form-control',
                    'tooltip' => 'mautic.bpmessage.form.step_foreign_id.tooltip',
                ],
                'required' => false,
            ]
        );

        // Email From Override (optional)
        $builder->add(
            'email_from',
            TextType::class,
            [
                'label'      => 'mautic.bpmessage.form.email_from_override',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'       => 'form-control',
                    'tooltip'     => 'mautic.bpmessage.form.email_from_override.tooltip',
                    'placeholder' => 'Leave empty to use template default',
                ],
                'required' => false,
            ]
        );

        // Email To Override (optional)
        $builder->add(
            'email_to',
            TextType::class,
            [
                'label'      => 'mautic.bpmessage.form.email_to_override',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'       => 'form-control',
                    'tooltip'     => 'mautic.bpmessage.form.email_to_override.tooltip',
                    'placeholder' => '{contactfield=email}',
                ],
                'required' => false,
            ]
        );

        // Email Template Selection
        $builder->add(
            'email_template',
            EmailListType::class,
            [
                'label'      => 'mautic.bpmessage.form.email_template',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'   => 'form-control',
                    'tooltip' => 'mautic.bpmessage.form.email_template.tooltip',
                ],
                'email_type'  => 'template',
                'multiple'    => false,
                'required'    => true,
                'constraints' => [
                    new NotBlank(['message' => 'mautic.bpmessage.email_template.notblank']),
                ],
            ]
        );

        // Additional Data - Custom fields for email message
        $builder->add(
            'additional_data',
            KeyValueListType::class,
            [
                'required' => false,
                'label'    => 'mautic.bpmessage.form.additional_data',
                'attr'     => [
                    'tooltip' => 'mautic.bpmessage.form.additional_data_email.tooltip',
                ],
            ]
        );

        // Email Variables - Custom variables array
        $builder->add(
            'email_variables',
            KeyValueListType::class,
            [
                'required' => false,
                'label'    => 'mautic.bpmessage.form.email_variables',
                'attr'     => [
                    'tooltip' => 'mautic.bpmessage.form.email_variables.tooltip',
                ],
            ]
        );

        // Control
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

        $builder->add(
            'is_radar_lot',
            ChoiceType::class,
            [
                'label'   => 'mautic.bpmessage.form.is_radar_lot',
                'choices' => [
                    'mautic.core.yes' => true,
                    'mautic.core.no'  => false,
                ],
                'attr'     => ['class' => 'form-control'],
                'data'     => false,
                'required' => false,
            ]
        );
    }

    public function getBlockPrefix(): string
    {
        return 'bpmessage_email_template_action';
    }
}
