<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Administration\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\LocaleAwareInterface;

/**
 * Per-request locale resolver.
 *
 * Resolution order (first non-null wins):
 *   1. The authenticated user's persisted `locale` preference, when set.
 *   2. The hard-coded default 'es' (Spanish — the project's default UI).
 *
 * Runs at priority 4 on `kernel.request`, which is *below* Symfony's
 * built-in `LocaleListener` (16) and `LocaleAwareListener` (15) — they
 * pre-populate the locale from URL / session / Accept-Language / etc.
 * — and *below* the security firewall (priority 8). That ordering
 * means the firewall has already resolved the current User by the time
 * we read `Security::getUser()`, so the persisted preference can be
 * honoured.
 *
 * Two writes happen here:
 *   - `$request->setLocale($locale)` so the Request itself reflects the
 *     new locale (drives `app.request.locale` in templates and
 *     SameOriginCsrfTokenManager etc.).
 *   - `$translator->setLocale($locale)` so the Translator service's
 *     cached locale is rewritten. The Translator caches `getLocale()`
 *     on first call; if any service (the security bundle, the form
 *     builder, the validators) called `trans()` before this listener
 *     ran, the cache would otherwise stick on `default_locale` and
 *     Twig's `trans` filter would keep returning English.
 *
 * Anonymous traffic — `/login`, `/account/confirm`, the `/` home page —
 * falls through to 'es' because there's no user to consult. The
 * locale-switcher in `/account/profile-settings/locale` only mutates the
 * signed-in User's preference; it does not write a cookie, so logging
 * out returns the unauthenticated flow to Spanish.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final class LocaleResolver
{
    public function __construct(
        private readonly Security $security,
        private readonly LocaleAwareInterface $translator,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        $isUser = $user instanceof User;
        $userLocale = $isUser ? $user->getLocale() : null;
        $locale = $isUser && $userLocale !== null ? $userLocale : 'es';

        $request = $event->getRequest();
        $request->setLocale($locale);
        $this->translator->setLocale($locale);
    }
}
