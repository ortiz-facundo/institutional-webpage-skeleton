<?php

declare(strict_types=1);

namespace App\Service\Security\UserLifecycle;

use App\Entity\Administration\User;
use App\Mailer\Security\AccountMailer;
use Psr\Log\LoggerInterface;

/**
 * Transition to USER_STATE_DISABLED.
 *
 * Allowed previous states: PENDING (admin disables before activation) or
 * ACTIVE (admin disables an active user). Context is the optional
 * admin-supplied reason (string|null) — recorded in the audit entry.
 *
 * Side effect: any active confirmation tokens for this user are
 * invalidated. The user can't re-enable themselves by clicking an old
 * invitation link.
 */
final class ChangeUserStateToDisabled extends ChangeUserState
{
    public const string USER_CHANGE_TO = User::USER_STATE_DISABLED;

    /** @var list<string> */
    public const array PREVIOUS_VALID_STATES = [
        User::USER_STATE_PENDING,
        User::USER_STATE_ACTIVE,
    ];

    public function __construct(
        private readonly AccountTokenInvalidator $tokenInvalidator,
        private readonly AccountMailer $mailer,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    protected function checkValidations(User $user, mixed $context = null): void
    {
        if (!in_array($user->getState(), self::PREVIOUS_VALID_STATES, true)) {
            throw new \DomainException(sprintf(
                'Cannot disable a user in state "%s".',
                $user->getState()
            ));
        }
    }

    protected function changeStateTo(User $user, mixed $context = null): void
    {
        $user->setState(self::USER_CHANGE_TO);
        // Any pending confirmation tokens are dead now.
        $this->tokenInvalidator->invalidateAllForUser($user);
        // Notify the user (best-effort; the mailer throws would still leave
        // the state transition committed because the flush already ran).
        $this->mailer->sendDisabled($user, is_string($context) ? $context : null);
    }

    protected function logAudit(User $user, string $from, string $to, mixed $context): void
    {
        $this->auditLogger->info('user.state.disabled', [
            'user_id'    => $user->getId(),
            'user_email' => $user->getEmail(),
            'from'       => $from,
            'to'         => $to,
            'reason'     => is_string($context) ? $context : null,
        ]);
    }
}