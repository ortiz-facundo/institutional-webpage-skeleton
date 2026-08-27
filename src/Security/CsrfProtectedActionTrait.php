<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Shared CSRF guard for controller actions that mutate state via a plain
 * POST (delete/impersonate/resend/exit-impersonation). Requires the using
 * class to expose `isCsrfTokenValid()` (provided by Symfony's
 * AbstractController) and a `TranslatorInterface $translator` property.
 */
trait CsrfProtectedActionTrait
{
    private function assertValidCsrfToken(Request $request, string $tokenId): void
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid($tokenId, $token)) {
            throw new AccessDeniedException(
                $this->translator->trans('app.shared.errors.invalid_csrf')
            );
        }
    }
}
