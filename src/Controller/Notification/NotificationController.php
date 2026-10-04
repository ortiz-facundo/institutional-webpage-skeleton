<?php

declare(strict_types=1);

namespace App\Controller\Notification;

use App\Entity\Administration\User;
use App\Service\Notification\NotificationInbox;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class NotificationController extends AbstractController
{
    #[Route('/account/notifications', name: 'notification_list', methods: ['GET'])]
    public function list(Request $request, NotificationInbox $inbox): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $unread = $request->query->getBoolean('unread');
        $pages = max(1, (int) ceil($inbox->count($user, $unread) / 20));
        $page = min($pages, max(1, $request->query->getInt('page', 1)));
        return $this->render('notification/index.html.twig', [
            'notifications' => array_map($inbox->present(...), $inbox->list($user, $page, $unread)),
            'unreadCount' => $inbox->count($user, true), 'page' => $page, 'pages' => $pages, 'unread' => $unread,
        ]);
    }

    #[Route('/account/notifications/feed', name: 'notification_feed', methods: ['GET'])]
    public function feed(NotificationInbox $inbox): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $response = $this->json(['unreadCount' => $inbox->count($user, true), 'items' => array_map($inbox->present(...), $inbox->list($user, limit: 8))]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    #[Route('/account/notifications/{id}/open', name: 'notification_open', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function open(int $id, NotificationInbox $inbox): Response
    {
        $notification = $inbox->findOwned($this->getUser(), $id);
        if ($notification->getRequiredPermission() !== null) { $this->denyAccessUnlessGranted($notification->getRequiredPermission()); }
        // GET never mutates read state. UI uses the CSRF-protected read action.
        return $this->redirectToRoute($notification->getDestinationRoute(), $notification->getDestinationParameters());
    }

    #[Route('/account/notifications/{id}/read', name: 'notification_read', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function read(int $id, Request $request, NotificationInbox $inbox): Response
    {
        $this->checkToken($request);
        $inbox->markAsRead($this->getUser(), $id);
        return $request->headers->get('Accept') === 'application/json' ? $this->json(['ok' => true]) : $this->redirectToRoute('notification_list');
    }

    #[Route('/account/notifications/read-all', name: 'notification_read_all', methods: ['POST'])]
    public function readAll(Request $request, NotificationInbox $inbox): Response
    {
        $this->checkToken($request);
        $inbox->markAllAsRead($this->getUser());
        return $request->headers->get('Accept') === 'application/json' ? $this->json(['ok' => true]) : $this->redirectToRoute('notification_list');
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('notification_inbox', $request->headers->get('X-CSRF-Token') ?? $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }
}
