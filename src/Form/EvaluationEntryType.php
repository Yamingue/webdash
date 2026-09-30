<?php

namespace App\Form;

use App\Entity\Evaluation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\PositiveOrZero;

/** Une ligne de la saisie hebdomadaire : les champs sont désactivés si l'évaluation est verrouillée. */
final class EvaluationEntryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event) use ($options): void {
            $evaluation = $event->getData();
            $form = $event->getForm();
            $disabled = !$evaluation instanceof Evaluation || !$evaluation->isEditable();

            if ($options['can_edit_target']) {
                $form->add('target', NumberType::class, [
                    'label' => 'Objectif', 'html5' => true, 'scale' => 2, 'disabled' => $disabled,
                    'constraints' => [new NotBlank(), new PositiveOrZero()],
                ]);
            }
            $form
                ->add('score', NumberType::class, [
                    'label' => 'Score', 'required' => false, 'html5' => true, 'scale' => 2, 'disabled' => $disabled,
                    'constraints' => [new PositiveOrZero()],
                ])
                ->add('comment', TextareaType::class, [
                    'label' => 'Commentaire', 'required' => false, 'disabled' => $disabled,
                    'attr' => ['rows' => 1],
                ]);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Evaluation::class, 'can_edit_target' => false]);
    }
}
