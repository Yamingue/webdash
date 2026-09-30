<?php

namespace App\DataFixtures;

use App\Entity\Domain;
use App\Entity\Kpi;
use App\Entity\Membership;
use App\Entity\User;
use App\Enum\MembershipRole;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AppFixtures extends Fixture
{
    public function __construct(private readonly UserPasswordHasherInterface $hasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $definitions = [
            ['Réseau & ARCEP', 'reseau-arcep', 'lucide:wifi', [['SCAT1', 'Score SCAT1', 95, '%']]],
            ['Finance', 'finance', 'lucide:wallet', [['SCATX', 'Score SCATX', 100, '%']]],
        ];

        $domains = [];
        foreach ($definitions as $i => [$name, $slug, $icon, $subs]) {
            $domain = (new Domain())->setName($name)->setSlug($slug)->setIcon($icon)->setPosition($i);
            foreach ($subs as $j => [$code, $subName, $target, $unit]) {
                $domain->addKpi(
                    (new Kpi())->setCode($code)->setName($subName)->setDefaultTarget($target)->setUnit($unit)->setPosition($j)
                );
            }
            $manager->persist($domain);
            $domains[$slug] = $domain;
        }

        $admin = $this->user('admin@example.com', 'Admin', ['ROLE_ADMIN']);
        $director = $this->user('director@example.com', 'Directeur', ['ROLE_DIRECTOR']);
        $multi = $this->user('multi@example.com', 'Évaluateur multi-domaines');
        $multi->addMembership(new Membership($multi, $domains['reseau-arcep'], MembershipRole::Evaluator));
        $multi->addMembership(new Membership($multi, $domains['finance'], MembershipRole::Viewer));
        $manager_ = $this->user('manager@example.com', 'Responsable Réseau');
        $manager_->addMembership(new Membership($manager_, $domains['reseau-arcep'], MembershipRole::Manager));

        foreach ([$admin, $director, $multi, $manager_] as $user) {
            $manager->persist($user);
        }
        $manager->flush();
    }

    /** @param list<string> $roles */
    private function user(string $email, string $name, array $roles = []): User
    {
        $user = (new User())->setEmail($email)->setFullName($name)->setRoles($roles);

        return $user->setPassword($this->hasher->hashPassword($user, 'password'));
    }
}
