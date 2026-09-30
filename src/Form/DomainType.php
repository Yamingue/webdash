<?php

namespace App\Form;

use App\Entity\Domain;
use App\Service\IconCatalog;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\String\Slugger\SluggerInterface;

final class DomainType extends AbstractType
{
    public function __construct(
        private readonly IconCatalog $icons,
        private readonly SluggerInterface $slugger,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Nom'])
            ->add('slug', TextType::class, [
                'label' => 'Identifiant d\'URL',
                'required' => false,
                'help' => 'Généré à partir du nom si laissé vide.',
            ])
            ->add('icon', ChoiceType::class, [
                'label' => 'Icône',
                'choices' => $this->icons->choices(),
                'expanded' => true,
                'choice_translation_domain' => false,
            ])
            ->add('position', IntegerType::class, ['label' => 'Ordre dans le menu'])
            ->add('active', CheckboxType::class, ['label' => 'Active (visible dans le menu)', 'required' => false])
            ->add('kpis', CollectionType::class, [
                'label' => 'KPI',
                'entry_type' => KpiType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
            ])
            ->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
                $data = $event->getData();
                if ('' === trim((string) ($data['slug'] ?? '')) && '' !== trim((string) ($data['name'] ?? ''))) {
                    $data['slug'] = strtolower($this->slugger->slug($data['name']));
                    $event->setData($data);
                }
            });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Domain::class]);
    }
}
