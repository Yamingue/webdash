<?php

namespace App\Form;

use App\Entity\Domain;
use App\Entity\Membership;
use App\Enum\MembershipRole;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class MembershipType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('domain', EntityType::class, [
                'class' => Domain::class,
                'choice_label' => 'name',
                'label' => 'Domaine',
                'placeholder' => 'Choisir…',
            ])
            ->add('role', EnumType::class, [
                'class' => MembershipRole::class,
                'choice_label' => static fn (MembershipRole $role): string => $role->label(),
                'label' => 'Rôle',
                'placeholder' => 'Choisir…',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Membership::class]);
    }
}
