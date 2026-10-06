<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class ContactFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'contact.name',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 2, max: 120)],
            ])
            ->add('email', EmailType::class, [
                'label' => 'contact.email',
                'constraints' => [new Assert\NotBlank(), new Assert\Email(), new Assert\Length(max: 254)],
            ])
            ->add('phone', TelType::class, [
                'label' => 'contact.phone',
                'required' => false,
                'constraints' => [new Assert\Length(max: 30)],
            ])
            ->add('message', TextareaType::class, [
                'label' => 'contact.message',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 5, max: 5000)],
            ])
            ->add('hp_url_a8f3c', TextType::class, [
                'mapped' => false,
                'required' => false,
                'label' => false,
                'attr' => ['autocomplete' => 'off', 'tabindex' => '-1', 'aria-hidden' => 'true'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ContactInquiry::class,
            'csrf_token_id' => 'contact_form',
            'translation_domain' => 'messages',
            'action' => '',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'contact';
    }
}
