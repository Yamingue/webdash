<?php

namespace App\Controller;

use App\Entity\Domain;
use App\Entity\Evaluation;
use App\Entity\User;
use App\Repository\EvaluationRepository;
use App\Security\DomainVoter;
use App\Security\EvaluationVoter;
use App\Service\SafeFlusher;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Validation des évaluations soumises par le responsable du domaine. */
final class ValidationController extends AbstractController
{
    #[Route('/d/{slug}/validation', name: 'app_validation_index', methods: ['GET'])]
    #[IsGranted(DomainVoter::MANAGE, subject: 'domain')]
    public function index(#[MapEntity(mapping: ['slug' => 'slug'])] Domain $domain, EvaluationRepository $evaluations): Response
    {
        return $this->render('validation/index.html.twig', [
            'domain' => $domain,
            'evaluations' => $evaluations->findSubmittedForDomain($domain),
            'validated' => $evaluations->findRecentlyValidatedForDomain($domain),
        ]);
    }

    #[Route('/evaluations/{id}/reopen', name: 'app_evaluation_reopen', methods: ['POST'])]
    #[IsGranted(EvaluationVoter::REOPEN, subject: 'evaluation')]
    public function reopen(Evaluation $evaluation, Request $request, SafeFlusher $flusher): RedirectResponse
    {
        $this->checkToken($request, 'review_'.$evaluation->getId());

        $reason = trim($request->request->getString('reason'));
        if ('' === $reason) {
            $this->addFlash('error', 'Un motif est obligatoire pour déverrouiller une évaluation.');

            return $this->backToList($evaluation->getKpi()->getDomain());
        }

        $evaluation->reopen($reason);
        if (!$flusher->flush()) {
            return $this->conflict($evaluation->getKpi()->getDomain());
        }
        $this->addFlash('success', \sprintf('%s déverrouillé : renvoyé en brouillon à l\'évaluateur.', $evaluation->getKpi()->getCode()));

        return $this->backToList($evaluation->getKpi()->getDomain());
    }

    #[Route('/evaluations/{id}/validate', name: 'app_evaluation_validate', methods: ['POST'])]
    #[IsGranted(EvaluationVoter::REVIEW, subject: 'evaluation')]
    public function validate(Evaluation $evaluation, Request $request, SafeFlusher $flusher, #[CurrentUser] User $user): RedirectResponse
    {
        $this->checkToken($request, 'review_'.$evaluation->getId());

        $evaluation->validate($user);
        if (!$flusher->flush()) {
            return $this->conflict($evaluation->getKpi()->getDomain());
        }
        $this->addFlash('success', \sprintf('%s validé pour la semaine du %s.', $evaluation->getKpi()->getCode(), $evaluation->getWeekStart()->format('d/m/Y')));

        return $this->backToList($evaluation->getKpi()->getDomain());
    }

    #[Route('/evaluations/{id}/reject', name: 'app_evaluation_reject', methods: ['POST'])]
    #[IsGranted(EvaluationVoter::REVIEW, subject: 'evaluation')]
    public function reject(Evaluation $evaluation, Request $request, SafeFlusher $flusher): RedirectResponse
    {
        $this->checkToken($request, 'review_'.$evaluation->getId());

        $reason = trim($request->request->getString('reason'));
        if ('' === $reason) {
            $this->addFlash('error', 'Un motif est obligatoire pour rejeter une évaluation.');

            return $this->backToList($evaluation->getKpi()->getDomain());
        }

        $evaluation->reject($reason);
        if (!$flusher->flush()) {
            return $this->conflict($evaluation->getKpi()->getDomain());
        }
        $this->addFlash('success', \sprintf('%s rejeté : renvoyé en brouillon à l\'évaluateur.', $evaluation->getKpi()->getCode()));

        return $this->backToList($evaluation->getKpi()->getDomain());
    }

    #[Route('/d/{slug}/validation/validate-all', name: 'app_validation_validate_all', methods: ['POST'])]
    #[IsGranted(DomainVoter::MANAGE, subject: 'domain')]
    public function validateAll(
        #[MapEntity(mapping: ['slug' => 'slug'])] Domain $domain,
        Request $request,
        EvaluationRepository $evaluations,
        SafeFlusher $flusher,
        #[CurrentUser] User $user,
    ): RedirectResponse {
        $this->checkToken($request, 'validate_all_'.$domain->getSlug());

        $count = 0;
        foreach ($evaluations->findSubmittedForDomain($domain) as $evaluation) {
            $evaluation->validate($user);
            ++$count;
        }
        if (!$flusher->flush()) {
            return $this->conflict($domain);
        }
        $this->addFlash('success', \sprintf('%d évaluation(s) validée(s).', $count));

        return $this->backToList($domain);
    }

    private function checkToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }

    /** Une autre personne a modifié ces évaluations entre-temps : rien n'est enregistré, on recharge la liste à jour. */
    private function conflict(Domain $domain): RedirectResponse
    {
        $this->addFlash('warning', 'Ces évaluations viennent d\'être modifiées par quelqu\'un d\'autre. Votre action n\'a pas été appliquée : vérifiez l\'état à jour, puis recommencez si nécessaire.');

        return $this->backToList($domain);
    }

    private function backToList(Domain $domain): RedirectResponse
    {
        return $this->redirectToRoute('app_validation_index', ['slug' => $domain->getSlug()]);
    }
}
