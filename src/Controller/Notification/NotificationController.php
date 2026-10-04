<?php

declare(strict_types=1);

namespace App\Controller\Notification;

use App\Controller\CrudListQueryTrait;
use App\Entity\Administration\User;
use App\Service\Notification\NotificationInbox;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class NotificationController extends AbstractController
{
    use CrudListQueryTrait;

    private const array PER_PAGE_OPTIONS = [10, 25, 50];

    #[Route('/account/notifications', name: 'notification_list', methods: ['GET'])]
    public function list(Request $request, NotificationInbox $inbox): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $filter = $this->resolveFilter($request);
        $unread = $filter === 'unread';
        $archived = $filter === 'archived';
        $total = $inbox->count($user, $unread, $archived);
        $perPage = $this->resolvePerPage($request->query->get('per_page'), self::PER_PAGE_OPTIONS, 25);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($pages, $this->resolvePage($request->query->get('page')));
        return $this->render('notification/index.html.twig', [
            'notifications' => array_map($inbox->present(...), $inbox->list($user, $page, $unread, $perPage, $archived)),
            'inboxCount' => $filter === 'inbox' ? $total : $inbox->count($user),
            'archivedCount' => $archived ? $total : $inbox->count($user, archived: true),
            'unreadCount' => $inbox->count($user, true), 'unread' => $unread, 'archived' => $archived, 'filter' => $filter,
            'page' => $page, 'per_page' => $perPage, 'per_page_options' => self::PER_PAGE_OPTIONS,
            'total' => $total, 'total_pages' => $pages,
            'current_url' => $request->getRequestUri(), 'current_params' => $request->query->all(),
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
        return $this->mutationResponse($request);
    }

    #[Route('/account/notifications/read-all', name: 'notification_read_all', methods: ['POST'])]
    public function readAll(Request $request, NotificationInbox $inbox): Response
    {
        $this->checkToken($request);
        $inbox->markAllAsRead($this->getUser());
        return $this->mutationResponse($request);
    }

    #[Route('/account/notifications/{id}/archive', name: 'notification_archive', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function archive(int $id, Request $request, NotificationInbox $inbox, TranslatorInterface $translator): Response
    {
        $this->checkToken($request);
        try {
            $inbox->archive($this->getUser(), $id);
        } catch (\DomainException $e) {
            $message = $translator->trans($e->getMessage());
            if ($request->headers->get('Accept') === 'application/json') { return $this->json(['error' => $message], 409); }
            $this->addFlash('error', $message);
        }
        return $this->mutationResponse($request);
    }

    #[Route('/account/notifications/{id}/restore', name: 'notification_restore', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function restore(int $id, Request $request, NotificationInbox $inbox): Response
    {
        $this->checkToken($request);
        $inbox->restore($this->getUser(), $id);
        return $this->mutationResponse($request);
    }

    private function resolveFilter(Request $request): string
    {
        // Retain existing bookmarked ?unread=1 URLs.
        $filter = $request->query->getString('filter', $request->query->getBoolean('unread') ? 'unread' : 'inbox');
        return in_array($filter, ['inbox', 'unread', 'archived'], true) ? $filter : 'inbox';
    }

    private function mutationResponse(Request $request): Response
    {
        if ($request->headers->get('Accept') === 'application/json') { return $this->json(['ok' => true]); }
        return $this->redirectToRoute('notification_list', [
            'filter' => $this->resolveFilter($request),
            'page' => $this->resolvePage($request->query->get('page')),
            'per_page' => $this->resolvePerPage($request->query->get('per_page'), self::PER_PAGE_OPTIONS, 25),
        ]);
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('notification_inbox', $request->headers->get('X-CSRF-Token') ?? $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }
}
