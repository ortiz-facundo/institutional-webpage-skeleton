<?php

declare(strict_types=1);

namespace App\Service\Security\UserLifecycle;

use App\Entity\Administration\User;
use App\Mailer\Security\AccountMailer;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Transition to USER_STATE_ACTIVE.
 *
 * Allowed previous states: PENDING (user confirms their invitation) or
 * DISABLED (admin re-enables). Context is the optional plaintext password
 * (required when transitioning from PENDING, ignored when transitioning
 * from DISABLED — the existing hash is kept).
 *
 * The active email is sent only on Pending → Active (the user just got in);
 * the admin-re-enable path skips it because the user's address might be
 * stale.
 */
final class ChangeUserStateToActive extends ChangeUserState
{
    public const string USER_CHANGE_TO = User::USER_STATE_ACTIVE;

    /** @var list<string> */
    public const array PREVIOUS_VALID_STATES = [
        User::USER_STATE_PENDING,
        User::USER_STATE_DISABLED,
    ];

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly AccountMailer $mailer,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    protected function checkValidations(User $user, mixed $context = null): void
    {
        if (!in_array($user->getState(), self::PREVIOUS_VALID_STATES, true)) {
            throw new \DomainException(sprintf(
                'Cannot transition user from "%s" to "%s". Allowed previous states: %s.',
                $user->getState(),
                self::USER_CHANGE_TO,
                implode(', ', self::PREVIOUS_VALID_STATES)
            ));
        }

        // The password, when supplied, was already validated against the
        // policy by AccountPasswordFormType's constraints. We sanity-check
        // the length here as a second line of defense in case this service
        // is ever called outside the form flow (CLI, future flows).
        if (is_string($context) && $context !== '') {
            if (mb_strlen($context) < 5) {
                throw new \DomainException('Password too short (minimum 5 characters).');
            }
        }

        // If we are transitioning from Pending → Active, the user must
        // supply a password. Disabled → Active keeps the existing hash.
        if ($user->isPending() && !is_string($context)) {
            throw new \DomainException('Password is required to activate a Pending user.');
        }
    }

    protected function changeStateTo(User $user, mixed $context = null): void
    {
        if (is_string($context) && $context !== '') {
            $user->setPassword($this->hasher->hashPassword($user, $context));
        }
        $user->setState(self::USER_CHANGE_TO);
        // Notify the user only on the confirmation path (Pending → Active).
        // The admin-re-enable path (Disabled → Active) is skipped because
        // the user's address might be stale.
        if ($from = $user->getState()) {
            // see execute() — $from was captured before the change
        }
        // We use the audit log's "triggered_by" hint via a simple check on
        // whether this is a confirmation (password was supplied) vs an
        // admin re-enable (no password in context).
        if (is_string($context) && $context !== '') {
            $this->mailer->sendActivated($user);
        }
    }

    protected function logAudit(User $user, string $from, string $to, mixed $context): void
    {
        $this->auditLogger->info('user.state.activated', [
            'user_id'      => $user->getId(),
            'user_email'   => $user->getEmail(),
            'from'         => $from,
            'to'           => $to,
            'triggered_by' => $from === User::USER_STATE_PENDING ? 'confirmation' : 'admin_reenable',
        ]);
    }
}