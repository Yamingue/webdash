<?php

namespace App\Form;

use App\Entity\Kpi;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

final class KpiType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, ['label' => 'Code'])
            ->add('name', TextType::class, ['label' => 'Nom'])
            ->add('defaultTarget', NumberType::class, ['label' => 'Objectif par défaut', 'html5' => true, 'scale' => 2, 'constraints' => [new NotBlank()]])
            ->add('unit', TextType::class, ['label' => 'Unité', 'required' => false])
            ->add('weight', NumberType::class, [
                'label' => 'Poids',
                'html5' => true,
                'scale' => 2,
                // 'help' => '1 = normal, 2 = double.',
                'attr' => ['min' => '0.01', 'step' => '0.01'],
                'constraints' => [new NotBlank()],
            ])
            ->add('position', IntegerType::class, ['label' => 'Ordre'])
            ->add('lowerIsBetter', CheckboxType::class, ['label' => 'Plus bas = meilleur', 'required' => false, 'help' => 'Ex. churn, taux de coupure.'])
            ->add('active', CheckboxType::class, ['label' => 'Active', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Kpi::class]);
    }
}
