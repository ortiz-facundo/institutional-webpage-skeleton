<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Form\DataTransformer\PhoneDataTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** Optional international phone string, stored as digits including the country code. */
final class PhoneInputType extends AbstractType
{
    public function __construct(private readonly PhoneDataTransformer $transformer) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('country_code', ChoiceType::class, [
                'label' => 'app.shared.phone.country',
                'choices' => ['🇦🇷 +54' => '54', '🇺🇾 +598' => '598', '🇨🇱 +56' => '56', '🇧🇷 +55' => '55', '🇵🇾 +595' => '595'],
                'constraints' => [new Assert\Choice(choices: PhoneDataTransformer::COUNTRY_CODES)],
            ])
            ->add('area_code', TextType::class, [
                'label' => 'app.shared.phone.area',
                'required' => false,
                'attr' => ['inputmode' => 'numeric', 'maxlength' => 6, 'autocomplete' => 'off', 'placeholder' => '11'],
                'constraints' => [new Assert\Regex(pattern: '/^\d{0,6}$/', message: 'app.shared.validators.digits_only')],
            ])
            ->add('number', TextType::class, [
                'label' => 'app.shared.phone.number',
                'required' => false,
                'attr' => ['inputmode' => 'numeric', 'maxlength' => 10, 'autocomplete' => 'off', 'placeholder' => '30649377'],
                'constraints' => [new Assert\Regex(pattern: '/^\d{0,10}$/', message: 'app.shared.validators.digits_only')],
            ])
            ->addModelTransformer($this->transformer);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['compound' => true, 'data_class' => null, 'inherit_data' => false]);
    }

    public function getBlockPrefix(): string { return 'phone_input'; }
}
