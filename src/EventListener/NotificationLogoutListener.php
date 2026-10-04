<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Administration\User;
use App\Service\Notification\ManagePushSubscriptions;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;

#[AsEventListener(event: LogoutEvent::class, priority: -128)]
final class NotificationLogoutListener
{
    public function __construct(private readonly ManagePushSubscriptions $subscriptions) {}
    public function __invoke(LogoutEvent $event): void
    {
        $token = $event->getToken();
        // Logout during impersonation detaches the actor's browser, never the
        // impersonated user's devices. Explicit logout still stops local pushes.
        $user = ($token instanceof SwitchUserToken ? $token->getOriginalToken() : $token)?->getUser();
        $id = $event->getRequest()->cookies->getInt('notification_device');
        if ($user instanceof User && $id > 0) {
            try { $this->subscriptions->disable($user, $id); } catch (NotFoundHttpException) { /* stale device cookie */ }
        }
        $event->getResponse()?->headers->clearCookie('notification_device');
    }
}
