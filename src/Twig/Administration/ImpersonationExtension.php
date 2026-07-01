<?php

declare(strict_types=1);

namespace App\Twig\Administration;

use App\Entity\Administration\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Twig\Attribute\AsTwigFunction;

/**
 * Twig helpers for impersonation-aware templates.
 *
 *   {{ is_impersonating() }}                 → bool
 *   {{ impersonator_email() }}                → original admin's email or null
 *
 * The backoffice banner uses these to render a "you are impersonating …"
 * notice that is impossible to ignore while a SwitchUserToken is active.
 */
final class ImpersonationExtension
{
    public function __construct(private readonly Security $security)
    {
    }

    #[AsTwigFunction('is_impersonating')]
    public function isImpersonating(): bool
    {
        return $this->security->getToken() instanceof SwitchUserToken;
    }

    #[AsTwigFunction('impersonator_email')]
    public function impersonatorEmail(): ?string
    {
        $token = $this->security->getToken();
        if (!$token instanceof SwitchUserToken) {
            return null;
        }
        $original = $token->getOriginalToken()->getUser();
        return $original instanceof User ? $original->getEmail() : null;
    }
}