<?php

namespace App\Tests\Security;

use App\Entity\Domain;
use App\Entity\Membership;
use App\Entity\User;
use App\Enum\MembershipRole;
use App\Security\DomainVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class DomainVoterTest extends KernelTestCase
{
    /** @return iterable<string, array{?MembershipRole, list<string>, string, bool}> */
    public static function cases(): iterable
    {
        yield 'admin manage' => [null, ['ROLE_ADMIN'], DomainVoter::MANAGE, true];
        yield 'director view' => [null, ['ROLE_DIRECTOR'], DomainVoter::VIEW, true];
        yield 'director cannot evaluate' => [null, ['ROLE_DIRECTOR'], DomainVoter::EVALUATE, false];
        yield 'outsider cannot view' => [null, [], DomainVoter::VIEW, false];
        yield 'viewer views' => [MembershipRole::Viewer, [], DomainVoter::VIEW, true];
        yield 'viewer cannot evaluate' => [MembershipRole::Viewer, [], DomainVoter::EVALUATE, false];
        yield 'evaluator evaluates' => [MembershipRole::Evaluator, [], DomainVoter::EVALUATE, true];
        yield 'evaluator cannot manage' => [MembershipRole::Evaluator, [], DomainVoter::MANAGE, false];
        yield 'manager manages' => [MembershipRole::Manager, [], DomainVoter::MANAGE, true];
    }

    /** @param list<string> $globalRoles */
    #[DataProvider('cases')]
    public function testVote(?MembershipRole $role, array $globalRoles, string $attribute, bool $expected): void
    {
        $domain = new Domain();
        $other = new Domain();
        $user = (new User())->setRoles($globalRoles);
        if (null !== $role) {
            $user->addMembership(new Membership($user, $domain, $role));
            // un rôle dans un domaine ne donne rien dans une autre
            $this->assertFalse($this->check($user, $attribute, $other));
        }

        $this->assertSame($expected, $this->check($user, $attribute, $domain));
    }

    private function check(User $user, string $attribute, Domain $domain): bool
    {
        self::bootKernel();
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $checker = self::getContainer()->get(AuthorizationCheckerInterface::class);
        self::getContainer()->get('security.token_storage')->setToken($token);

        return $checker->isGranted($attribute, $domain);
    }
}
