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
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Form type for BpMessage email campaign action.
 */
class BpMessageEmailActionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
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

        // CRM fields
        $builder->add(
            'crm_id',
            IntegerType::class,
            [
                'label'      => 'mautic.bpmessage.form.crm_id',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'   => 'form-control',
                    'tooltip' => 'mautic.bpmessage.form.crm_id.tooltip',
                ],
                'required'    => true,
                'constraints' => [
                    new NotBlank(['message' => 'mautic.bpmessage.crm_id.notblank']),
                ],
            ]
        );

        $builder->add(
            'book_business_foreign_id',
            TextType::class,
            [
                'label'      => 'mautic.bpmessage.form.book_business_foreign_id',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'   => 'form-control',
                    'tooltip' => 'mautic.bpmessage.form.book_business_foreign_id.tooltip',
                ],
                'required'    => true,
                'constraints' => [
                    new NotBlank(['message' => 'mautic.bpmessage.book_business_foreign_id.notblank']),
                ],
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

        // Email From
        $builder->add(
            'email_from',
            TextType::class,
            [
                'label'      => 'mautic.bpmessage.form.email_from',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'       => 'form-control',
                    'tooltip'     => 'mautic.bpmessage.form.email_from.tooltip',
                    'placeholder' => 'noreply@example.com',
                ],
                'required'    => true,
                'constraints' => [
                    new NotBlank(['message' => 'mautic.bpmessage.email_from.notblank']),
                    new Email(['message' => 'mautic.bpmessage.email_from.invalid']),
                ],
            ]
        );

        // Email Field - select contact field containing email address(es)
        $builder->add(
            'email_field',
            LeadFieldsType::class,
            [
                'label'      => 'mautic.bpmessage.form.email_field',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'   => 'form-control',
                    'tooltip' => 'mautic.bpmessage.form.email_field.tooltip',
                ],
                'required'            => true,
                'with_tags'           => false,
                'with_company_fields' => false,
            ]
        );

        // Email Limit - limit number of dispatches for collection fields
        $builder->add(
            'email_limit',
            IntegerType::class,
            [
                'label'      => 'mautic.bpmessage.form.email_limit',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'       => 'form-control',
                    'tooltip'     => 'mautic.bpmessage.form.email_limit.tooltip',
                    'placeholder' => '0',
                    'min'         => 0,
                ],
                'required' => false,
            ]
        );

        // Set default value for email_field when creating new action
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $data = $event->getData();
            if (is_array($data) && empty($data['email_field'])) {
                $data['email_field'] = 'email';
                $event->setData($data);
            }
        });

        // Email CC
        $builder->add(
            'email_cc',
            TextType::class,
            [
                'label'      => 'mautic.bpmessage.form.email_cc',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'       => 'form-control',
                    'tooltip'     => 'mautic.bpmessage.form.email_cc.tooltip',
                    'placeholder' => 'cc@example.com',
                ],
                'required' => false,
            ]
        );

        // Email Subject
        $builder->add(
            'email_subject',
            TextType::class,
            [
                'label'      => 'mautic.bpmessage.form.email_subject',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'       => 'form-control',
                    'tooltip'     => 'mautic.bpmessage.form.email_subject.tooltip',
                    'placeholder' => 'Hello {contactfield=firstname}!',
                ],
                'required'    => true,
                'constraints' => [
                    new NotBlank(['message' => 'mautic.bpmessage.email_subject.notblank']),
                ],
            ]
        );

        // Email Body
        $builder->add(
            'email_body',
            TextareaType::class,
            [
                'label'      => 'mautic.bpmessage.form.email_body',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'       => 'form-control',
                    'rows'        => 15,
                    'tooltip'     => 'mautic.bpmessage.form.email_body.tooltip',
                    'placeholder' => "<html>\n<body>\n  <h1>Hello {contactfield=firstname}!</h1>\n  <p>Your message here...</p>\n</body>\n</html>",
                ],
                'required' => false,
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
        return 'bpmessage_email_action';
    }
}
