<?php

namespace App\Security;

use App\Entity\Evaluation;
use App\Entity\User;
use App\Enum\EvaluationStatus;
use App\Enum\MembershipRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Décisions sur une évaluation, réservées au responsable du domaine (ou à l'admin) :
 * - EVALUATION_REVIEW : valider ou rejeter, tant que l'évaluation est soumise ;
 * - EVALUATION_REOPEN : déverrouiller une évaluation validée (motif obligatoire, journalisé).
 *
 * @extends Voter<string, Evaluation>
 */
final class EvaluationVoter extends Voter
{
    public const REVIEW = 'EVALUATION_REVIEW';
    public const REOPEN = 'EVALUATION_REOPEN';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::REVIEW, self::REOPEN], true) && $subject instanceof Evaluation;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $requiredStatus = self::REVIEW === $attribute ? EvaluationStatus::Submitted : EvaluationStatus::Validated;
        if ($requiredStatus !== $subject->getStatus()) {
            return false;
        }

        return $user->isAdmin()
            || MembershipRole::Manager === $user->getRoleIn($subject->getKpi()->getDomain());
    }
}
