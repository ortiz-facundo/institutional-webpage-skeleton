<?php

declare(strict_types=1);

namespace App\Controller\Notification;

use App\Entity\Administration\User;
use App\Service\Notification\{ManagePushSubscriptions, SendWebPushNotification};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Cookie, Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class PushSubscriptionController extends AbstractController
{
    #[Route('/account/profile-settings/notifications', name: 'account_preferences_notifications', methods: ['GET'])]
    public function settings(ManagePushSubscriptions $subscriptions, SendWebPushNotification $push): Response
    {
        return $this->render('notification/settings.html.twig', ['subscriptions' => $subscriptions->list($this->getUser()), 'publicKey' => $push->getPublicKey()]);
    }

    #[Route('/account/notifications/subscriptions/status', name: 'notification_subscription_status', methods: ['GET'])]
    public function status(Request $request, ManagePushSubscriptions $subscriptions): Response
    {
        $hash = $request->query->getString('endpointHash');
        $id = null;
        foreach ($subscriptions->list($this->getUser()) as $subscription) {
            if (hash_equals(hash('sha256', $subscription->getEndpoint()), $hash)) { $id = $subscription->getId(); }
        }
        $response = $this->json(['id' => $id]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    #[Route('/account/notifications/subscriptions', name: 'notification_subscribe', methods: ['POST'])]
    public function register(Request $request, ManagePushSubscriptions $subscriptions, SendWebPushNotification $push, TokenStorageInterface $tokens): Response
    {
        $this->checkMutation($request, $tokens);
        if (!$push->isConfigured()) { throw $this->createAccessDeniedException(); }
        if (strlen($request->getContent()) > 4096) { return $this->json(['ok' => false], 413); }
        $subscription = $subscriptions->register($this->getUser(), $request->toArray());
        $response = $this->json(['id' => $subscription->getId()]);
        // HTTP-only binding lets the server detach this device on logout even
        // when JS fails or another tab submits the logout form.
        $response->headers->setCookie(Cookie::create('notification_device', (string) $subscription->getId())->withSecure($request->isSecure())->withHttpOnly(true)->withSameSite('lax'));
        return $response;
    }

    #[Route('/account/notifications/subscriptions/{id}/disable', name: 'notification_unsubscribe', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function disable(int $id, Request $request, ManagePushSubscriptions $subscriptions, TokenStorageInterface $tokens): Response
    {
        $this->checkMutation($request, $tokens);
        $subscriptions->disable($this->getUser(), $id);
        $response = $request->headers->get('Accept') === 'application/json' ? $this->json(['ok' => true]) : $this->redirectToRoute('account_preferences_notifications');
        if ($request->cookies->getInt('notification_device') === $id) { $response->headers->clearCookie('notification_device'); }
        return $response;
    }

    private function checkMutation(Request $request, TokenStorageInterface $tokens): void
    {
        if ($tokens->getToken() instanceof SwitchUserToken || $this->getUser()->getState() !== User::USER_STATE_ACTIVE
            || !$this->isCsrfTokenValid('notification_subscription', $request->headers->get('X-CSRF-Token') ?? $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }
}
