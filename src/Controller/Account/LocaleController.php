<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Entity\Administration\User;
use App\Form\Account\LocaleType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Self-service "Language" section. The only enabled item under the
 * Preferences group of the unified /account/profile-settings hub.
 *
 * The switcher mutates the User's `locale` field and redirects back
 * to the same URL — the LocaleResolver event listener picks up the
 * new value on the next request and the entire UI re-renders in the
 * chosen language.
 *
 * No cookie is written: anonymous traffic always lands on Spanish
 * (the system default), regardless of any prior pick. The locale is
 * a property of the signed-in user, not of the browser.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class LocaleController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/account/profile-settings/locale',
        name: 'account_profile_settings_locale',
        methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $current = $user->getLocale() ?? 'es';

        $form = $this->createForm(LocaleType::class, null, [
            'current_locale' => $current,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $newLocale */
            $newLocale = (string) $form->get('locale')->getData();
            // setLocale() throws on unknown values; the ChoiceType
            // constraint already gates against that, but we keep the
            // belt-and-braces check.
            $user->setLocale($newLocale);
            $this->em->flush();

            // Translate the confirmation in the language just selected,
            // rather than the language of the request that submitted it.
            $request->setLocale($newLocale);
            $this->translator->setLocale($newLocale);

            $this->addFlash('success', $this->translator->trans('app.account.locale.saved'));
            return $this->redirectToRoute('account_profile_settings');
        }

        return $this->render('account/locale.html.twig', [
            'form' => $form,
        ]);
    }
}
