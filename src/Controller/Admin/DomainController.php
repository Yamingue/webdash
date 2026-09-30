<?php

namespace App\Controller\Admin;

use App\Entity\Domain;
use App\Form\DomainType;
use App\Repository\DomainRepository;
use App\Repository\EvaluationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/domains')]
#[IsGranted('ROLE_ADMIN')]
final class DomainController extends AbstractController
{
    #[Route('', name: 'app_admin_domain_index', methods: ['GET'])]
    public function index(DomainRepository $domains): Response
    {
        return $this->render('admin/domain/index.html.twig', [
            'domains' => $domains->findBy([], ['position' => 'ASC', 'name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'app_admin_domain_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $domain = (new Domain())->setPosition(\count($em->getRepository(Domain::class)->findAll()));

        return $this->handleForm($domain, $request, $em, 'Domaine créé.');
    }

    #[Route('/{id}/edit', name: 'app_admin_domain_edit', methods: ['GET', 'POST'])]
    public function edit(Domain $domain, Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm($domain, $request, $em, 'Domaine mis à jour.');
    }

    #[Route('/{id}/delete', name: 'app_admin_domain_delete', methods: ['POST'])]
    public function delete(Domain $domain, Request $request, EntityManagerInterface $em, EvaluationRepository $evaluations): Response
    {
        if (!$this->isCsrfTokenValid('delete_domain_'.$domain->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if ($evaluations->countForDomain($domain) > 0) {
            $this->addFlash('error', 'Ce domaine contient des évaluations : désactivez-le plutôt que de la supprimer.');

            return $this->redirectToRoute('app_admin_domain_index');
        }

        $em->remove($domain);
        $em->flush();
        $this->addFlash('success', 'Domaine supprimé.');

        return $this->redirectToRoute('app_admin_domain_index');
    }

    private function handleForm(Domain $domain, Request $request, EntityManagerInterface $em, string $message): Response
    {
        $form = $this->createForm(DomainType::class, $domain);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($domain);
            $em->flush();
            $this->addFlash('success', $message);

            return $this->redirectToRoute('app_admin_domain_index');
        }

        return $this->render('admin/domain/form.html.twig', [
            'domain' => $domain,
            'form' => $form,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
