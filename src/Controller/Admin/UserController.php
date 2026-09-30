<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\UserType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/users')]
#[IsGranted('ROLE_ADMIN')]
final class UserController extends AbstractController
{
    #[Route('', name: 'app_admin_user_index', methods: ['GET'])]
    public function index(UserRepository $users): Response
    {
        return $this->render('admin/user/index.html.twig', [
            'users' => $users->findBy([], ['fullName' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'app_admin_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasher): Response
    {
        return $this->handleForm(new User(), true, $request, $em, $hasher, 'Utilisateur créé.');
    }

    #[Route('/{id}/edit', name: 'app_admin_user_edit', methods: ['GET', 'POST'])]
    public function edit(User $user, Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasher): Response
    {
        return $this->handleForm($user, false, $request, $em, $hasher, 'Utilisateur mis à jour.');
    }

    private function handleForm(User $user, bool $isNew, Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasher, string $message): Response
    {
        $form = $this->createForm(UserType::class, $user, ['password_required' => $isNew]);
        if (!$form->isSubmitted()) {
            $form->get('globalRole')->setData(array_values(array_intersect(['ROLE_ADMIN', 'ROLE_DIRECTOR'], $user->getRoles()))[0] ?? null);
        }
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyGlobalRole($user, $form);
            if ($plain = $form->get('plainPassword')->getData()) {
                $user->setPassword($hasher->hashPassword($user, $plain));
            }

            $em->persist($user);
            $em->flush();
            $this->addFlash('success', $message);

            return $this->redirectToRoute('app_admin_user_index');
        }

        return $this->render('admin/user/form.html.twig', [
            'user_entity' => $user,
            'form' => $form,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    private function applyGlobalRole(User $user, FormInterface $form): void
    {
        $role = $form->get('globalRole')->getData();
        $user->setRoles($role ? [$role] : []);
    }
}
