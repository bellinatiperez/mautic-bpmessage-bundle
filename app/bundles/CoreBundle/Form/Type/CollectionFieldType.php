<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Form\Type;

use Mautic\CoreBundle\Form\DataTransformer\CollectionToJsonTransformer;
use Mautic\LeadBundle\Validator\Constraints\Length;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\ChoiceList\ArrayChoiceList;
use Symfony\Component\Form\ChoiceList\ChoiceListInterface;
use Symfony\Component\Form\ChoiceList\Loader\ChoiceLoaderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Choice loader that accepts any submitted value.
 */
class FreeInputChoiceLoader implements ChoiceLoaderInterface
{
    /** @var array<string, string> */
    private array $choices;

    /**
     * @param array<string, string> $initialChoices
     */
    public function __construct(array $initialChoices = [])
    {
        $this->choices = $initialChoices;
    }

    public function loadChoiceList(?callable $value = null): ChoiceListInterface
    {
        return new ArrayChoiceList($this->choices, $value);
    }

    /**
     * @param array<int, mixed> $values
     *
     * @return array<int, string>
     */
    public function loadChoicesForValues(array $values, ?callable $value = null): array
    {
        // Accept any value - add it to choices if not present
        foreach ($values as $v) {
            if ('' !== $v && null !== $v && !isset($this->choices[$v])) {
                $this->choices[$v] = $v;
            }
        }

        return $values;
    }

    /**
     * @param array<int, mixed> $choices
     *
     * @return array<int, string>
     */
    public function loadValuesForChoices(array $choices, ?callable $value = null): array
    {
        $result = [];
        foreach ($choices as $choice) {
            if ('' !== $choice && null !== $choice) {
                if (!isset($this->choices[$choice])) {
                    $this->choices[$choice] = $choice;
                }
                $result[] = $choice;
            }
        }

        return $result;
    }
}

/**
 * Collection field type - similar to tags but for custom values.
 * Allows free-form input of multiple values stored as JSON.
 *
 * @extends AbstractType<mixed>
 */
class CollectionFieldType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(
            new CollectionToJsonTransformer($options['value_type'] ?? 'string')
        );
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['value_type'] = $options['value_type'];
        $view->vars['attr']['data-allow-add'] = 'true';
        $view->vars['attr']['data-placeholder'] = $options['placeholder'] ?? '';
        $view->vars['attr']['data-no-results-text'] = $options['no_results_text'] ?? '';
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'label_attr'       => ['class' => 'control-label'],
            'attr'             => [
                'class' => 'form-control',
            ],
            'multiple'         => true,
            'expanded'         => false,
            'choices'          => [],
            'choice_loader'    => null, // Will be set dynamically
            'value_type'       => 'string',
            'placeholder'      => '',
            'no_results_text'  => '',
            'constraints'      => [new Length(['max' => 65535])],
        ]);

        // Set choice_loader based on choices option
        $resolver->setNormalizer('choice_loader', function ($options, $value) {
            // Create a FreeInputChoiceLoader with initial choices
            $initialChoices = $options['choices'] ?? [];

            return new FreeInputChoiceLoader($initialChoices);
        });
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'collection_field';
    }
}
