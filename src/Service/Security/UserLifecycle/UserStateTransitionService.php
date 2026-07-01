<?php

declare(strict_types=1);

namespace App\Service\Security\UserLifecycle;

use App\Entity\Administration\User;
use App\Mailer\Security\AccountMailer;
use Psr\Log\LoggerInterface;

/**
 * Convenience dispatcher for state transitions.
 *
 * Controllers inject this service instead of choosing between the concrete
 * `ChangeUserStateToActive` / `ChangeUserStateToDisabled` themselves — so
 * the `match` over the target state lives in one place. Adding a future
 * transition (e.g., a hypothetical USER_STATE_ARCHIVED) only requires a
 * new concrete service + a new arm here.
 *
 * `resendConfirmation` is grouped here because the "rotate token + send
 * email" flow is the same regardless of which admin clicked the button.
 */
final class UserStateTransitionService
{
    public function __construct(
        private readonly ChangeUserStateToActive $toActive,
        private readonly ChangeUserStateToDisabled $toDisabled,
        private readonly AccountTokenIssuer $tokenIssuer,
        private readonly AccountMailer $mailer,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    public function transitionTo(User $user, string $targetState, mixed $context = null): void
    {
        $service = match ($targetState) {
            User::USER_STATE_ACTIVE   => $this->toActive,
            User::USER_STATE_DISABLED => $this->toDisabled,
            default => throw new \InvalidArgumentException(sprintf(
                'No transition defined for target state "%s".',
                $targetState
            )),
        };
        $service->execute($user, $context);
    }

    /**
     * Rotate the user's confirmation token (invalidate prior active ones)
     * and email a new link. Allowed only for Pending users — Active and
     * Disabled users have either already confirmed or are blocked by
     * admin.
     */
    public function resendConfirmation(User $user): void
    {
        if (!$user->isPending()) {
            throw new \DomainException(sprintf(
                'Resend confirmation only for Pending users (current state: "%s").',
                $user->getState()
            ));
        }

        $token = $this->tokenIssuer->issue($user);
        $url = $this->buildConfirmationUrl($token);
        $this->mailer->sendConfirmation($user, $url);

        // Audit log: the URL is only useful with the raw secret, and the
        // secret has just been sent to the user via email. Logging it
        // here makes it easy to copy-paste in dev where MAILER_DSN is
        // null://null. In prod the email is the canonical delivery
        // channel; this log is a backup.
        $this->auditLogger->info('user.confirmation.resent', [
            'user_id'   => $user->getId(),
            'user_email'=> $user->getEmail(),
            'url'       => $url,
        ]);
    }

    public function buildConfirmationUrl(\App\Entity\Security\AccountConfirmationToken $token): string
    {
        return sprintf(
            '%s/account/confirm?selector=%s&secret=%s',
            rtrim($_ENV['APP_URL'] ?? 'http://localhost:8000', '/'),
            rawurlencode($token->getSelector()),
            rawurlencode((string) $token->getRawSecret())
        );
    }
}