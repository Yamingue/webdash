<?php

namespace App\Controller;

use App\Entity\Domain;
use App\Entity\Evaluation;
use App\Entity\User;
use App\Form\WeeklyEntryType;
use App\Repository\EvaluationRepository;
use App\Security\DomainVoter;
use App\Service\Week;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class EvaluationController extends AbstractController
{
    #[Route('/d/{slug}/saisie', name: 'app_evaluation_entry', methods: ['GET', 'POST'])]
    #[IsGranted(DomainVoter::EVALUATE, subject: 'domain')]
    public function entry(
        #[MapEntity(mapping: ["slug" => "slug"])] Domain $domain,
        Request $request,
        EvaluationRepository $repository,
        EntityManagerInterface $em,
        #[CurrentUser] User $user,
    ): Response {
        if (!$domain->isActive() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createNotFoundException();
        }

        $week = Week::resolve($request->query->getString('week') ?: null);
        $existing = $repository->findForDomainWeek($domain, $week);

        $evaluations = [];
        foreach ($domain->getKpis() as $kpi) {
            $evaluation = $existing[$kpi->getId()] ?? null;
            if (null === $evaluation && $kpi->isActive()) {
                $evaluation = (new Evaluation($kpi, $week))->setCreatedBy($user);
            }
            if (null !== $evaluation) {
                $evaluations[] = $evaluation;
            }
        }

        $form = $this->createForm(WeeklyEntryType::class, ['evaluations' => $evaluations], [
            'can_edit_target' => $this->isGranted(DomainVoter::MANAGE, $domain),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $submitting = $form->get('submit')->isClicked();
            $submitted = $incomplete = 0;

            foreach ($evaluations as $evaluation) {
                if (!$evaluation->isEditable()) {
                    continue;
                }
                $isNew = null === $evaluation->getId();
                if ($isNew && null === $evaluation->getScore() && !$evaluation->getComment()) {
                    continue; // ligne vide : rien à enregistrer
                }
                if ($submitting) {
                    if (null === $evaluation->getScore()) {
                        ++$incomplete;
                    } else {
                        $evaluation->submit();
                        ++$submitted;
                    }
                }
                $em->persist($evaluation);
            }
            $em->flush();

            if ($submitting) {
                $this->addFlash('success', \sprintf('%d évaluation(s) soumise(s).', $submitted));
                if ($incomplete > 0) {
                    $this->addFlash('warning', \sprintf('%d ligne(s) sans score n\'ont pas été soumises.', $incomplete));
                }
            } else {
                $this->addFlash('success', 'Brouillon enregistré.');
            }

            return $this->redirectToRoute('app_evaluation_entry', ['slug' => $domain->getSlug(), 'week' => $week->format('Y-m-d')]);
        }

        return $this->render('evaluation/entry.html.twig', [
            'domain' => $domain,
            'form' => $form,
            'week' => $week,
            'previousWeek' => $week->modify('-1 week'),
            'nextWeek' => $week->modify('+1 week'),
            'currentWeek' => Week::resolve(null),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
