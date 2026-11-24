<?php

namespace App\Security;

use App\Entity\Employee;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class LeaveVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === 'LEAVE_VIEW' && is_array($subject) && isset($subject['employee_id']);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof Employee && $user->active
            && ($user->role === 'ROLE_ADMIN' || $user->id === (int) $subject['employee_id']);
    }
}
