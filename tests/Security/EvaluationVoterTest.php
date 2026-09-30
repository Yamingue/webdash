<?php

namespace App\Tests\Security;

use App\Entity\Domain;
use App\Entity\Evaluation;
use App\Entity\Kpi;
use App\Entity\Membership;
use App\Entity\User;
use App\Enum\MembershipRole;
use App\Security\EvaluationVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class EvaluationVoterTest extends TestCase
{
    /** @return iterable<string, array{?MembershipRole, list<string>, bool, bool}> rôle, rôles globaux, soumise ?, attendu */
    public static function cases(): iterable
    {
        yield 'manager, soumise' => [MembershipRole::Manager, [], true, true];
        yield 'manager, brouillon' => [MembershipRole::Manager, [], false, false];
        yield 'admin, soumise' => [null, ['ROLE_ADMIN'], true, true];
        yield 'evaluateur, soumise' => [MembershipRole::Evaluator, [], true, false];
        yield 'lecteur, soumise' => [MembershipRole::Viewer, [], true, false];
        yield 'directeur, soumise' => [null, ['ROLE_DIRECTOR'], true, false];
        yield 'sans rattachement, soumise' => [null, [], true, false];
    }

    /** @param list<string> $globalRoles */
    #[DataProvider('cases')]
    public function testReview(?MembershipRole $role, array $globalRoles, bool $submitted, bool $expected): void
    {
        $domain = new Domain();
        $kpi = (new Kpi())->setDefaultTarget(100);
        $domain->addKpi($kpi);
        $evaluation = (new Evaluation($kpi, new \DateTimeImmutable()))->setScore(50);
        if ($submitted) {
            $evaluation->submit();
        }

        $user = (new User())->setRoles($globalRoles);
        if (null !== $role) {
            $user->addMembership(new Membership($user, $domain, $role));
        }

        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $result = (new EvaluationVoter())->vote($token, $evaluation, [EvaluationVoter::REVIEW]);

        $this->assertSame($expected ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED, $result);
    }

    public function testManagerOfAnotherDomainIsDenied(): void
    {
        $domain = new Domain();
        $kpi = (new Kpi())->setDefaultTarget(100);
        $domain->addKpi($kpi);
        $evaluation = (new Evaluation($kpi, new \DateTimeImmutable()))->setScore(50)->submit();

        $user = new User();
        $user->addMembership(new Membership($user, new Domain(), MembershipRole::Manager));

        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $this->assertSame(VoterInterface::ACCESS_DENIED, (new EvaluationVoter())->vote($token, $evaluation, [EvaluationVoter::REVIEW]));
    }
}
