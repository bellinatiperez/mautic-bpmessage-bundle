<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\SortableValueLabelListType;
use MauticPlugin\MauticBpMessageBundle\Form\DataTransformer\KeyValueListTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Custom list type that uses label as key (not value)
 * This allows duplicate values which is needed for date fields.
 */
class KeyValueListType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add(
            $builder->create(
                'list',
                CollectionType::class,
                [
                    'label'         => false,
                    'entry_type'    => SortableValueLabelListType::class,
                    'entry_options' => [
                        'label'    => false,
                        'required' => false,
                        'attr'     => [
                            'class'         => 'form-control',
                            'preaddon'      => 'ri-close-line',
                            'preaddon_attr' => [
                                'onclick' => 'Mautic.removeFormListOption(this);',
                            ],
                            'postaddon' => 'ri-draggable handle',
                        ],
                        'error_bubbling' => true,
                    ],
                    'allow_add'      => true,
                    'allow_delete'   => true,
                    'prototype'      => true,
                    'error_bubbling' => false,
                ]
            )
        )->addModelTransformer(new KeyValueListTransformer());
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['isSortable']     = true;
        $view->vars['addValueButton'] = 'mautic.core.form.list.additem';
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'required' => false,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'sortablelist';
    }
}
