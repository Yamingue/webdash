<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class WeeklyEntryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('evaluations', CollectionType::class, [
                'entry_type' => EvaluationEntryType::class,
                'entry_options' => ['can_edit_target' => $options['can_edit_target']],
                'allow_add' => false,
                'allow_delete' => false,
            ])
            ->add('save', SubmitType::class, ['label' => 'Enregistrer le brouillon'])
            ->add('submit', SubmitType::class, ['label' => 'Soumettre']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['can_edit_target' => false]);
        $resolver->setAllowedTypes('can_edit_target', 'bool');
    }
}
