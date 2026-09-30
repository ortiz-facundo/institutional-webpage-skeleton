<?php

declare(strict_types=1);

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Edits a list of strings as chips, using a pipe-delimited form carrier. */
final class TagsInputType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            static fn (?array $tags): string => implode('|', $tags ?? []),
            static fn (?string $raw): array => array_values(array_unique(array_filter(
                array_map('trim', explode('|', $raw ?? '')),
                static fn (string $tag): bool => $tag !== '',
            ))),
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['compound' => false, 'required' => false, 'placeholder' => '']);
        $resolver->setAllowedTypes('placeholder', 'string');
    }

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['attr']['placeholder'] = $options['placeholder'];
    }

    public function getBlockPrefix(): string { return 'tags_input'; }
}
