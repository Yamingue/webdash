<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $passwordConstraints = [new Length(min: 4)];
        if ($options['password_required']) {
            $passwordConstraints[] = new NotBlank();
        }

        $builder
            ->add('email', EmailType::class, ['label' => 'Email'])
            ->add('fullName', TextType::class, ['label' => 'Nom complet'])
            ->add('plainPassword', PasswordType::class, [
                'label' => 'Mot de passe',
                'mapped' => false,
                'required' => $options['password_required'],
                'help' => $options['password_required'] ? null : 'Laisser vide pour conserver le mot de passe actuel.',
                'constraints' => $passwordConstraints,
                'attr' => ['autocomplete' => 'new-password'],
            ])
            ->add('globalRole', ChoiceType::class, [
                'label' => 'Accès global',
                'mapped' => false,
                'required' => false,
                'placeholder' => 'Aucun (accès limité à ses catégories)',
                'choices' => [
                    'Directeur (lecture seule sur tout)' => 'ROLE_DIRECTOR',
                    'Administrateur' => 'ROLE_ADMIN',
                ],
            ])
            ->add('notifyByEmail', CheckboxType::class, [
                'label' => 'Recevoir les rappels hebdomadaires par e-mail',
                'required' => false,
            ])
            ->add('memberships', CollectionType::class, [
                'label' => 'Catégories et rôles',
                'entry_type' => MembershipType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class, 'password_required' => false]);
        $resolver->setAllowedTypes('password_required', 'bool');
    }
}
