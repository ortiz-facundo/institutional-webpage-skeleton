<?php

declare(strict_types=1);

namespace App\Crud\BulkAction;

use App\Security\Permission;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Collects every `App\Crud\BulkAction\BulkActionInterface` tagged
 * service (auto-tagged via `#[AutoconfigureTag]` on the interface) and
 * exposes a permission-filtered view.
 *
 * The registry is read-only at runtime; mutations would have to be
 * done at the DI level (the iterator is set at boot). The
 * `getVisibleForCurrentUser()` accessor hands a toolbar an
 * already-gated list so the template never has to call `is_granted()`
 * itself.
 */
final class BulkActionRegistry
{
    /**
     * @param iterable<BulkActionInterface> $actions
     */
    public function __construct(
        #[AutowireIterator('app.crud_bulk_action')]
        private readonly iterable $actions,
        private readonly Security $security,
    ) {
    }

    /**
     * @return list<BulkActionInterface>
     */
    public function getAll(): array
    {
        $out = [];
        foreach ($this->actions as $action) {
            $out[] = $action;
        }
        return $out;
    }

    /**
     * Filter to actions the current user is allowed to see. An action
     * with `getRequiredPermission() === null` is always visible.
     *
     * The list is ordered by `getId()` so the menu render is stable
     * across requests.
     *
     * @return list<BulkActionInterface>
     */
    public function getVisibleForCurrentUser(): array
    {
        $all = $this->getAll();
        $visible = array_values(array_filter(
            $all,
            fn (BulkActionInterface $action): bool => $this->isVisible($action)
        ));
        usort(
            $visible,
            fn (BulkActionInterface $a, BulkActionInterface $b): int
                => strcmp($a->getId(), $b->getId())
        );
        return $visible;
    }

    /**
     * Find an action by its id. Returns `null` when no action with
     * that id is registered.
     */
    public function getById(string $id): ?BulkActionInterface
    {
        foreach ($this->getAll() as $action) {
            if ($action->getId() === $id) {
                return $action;
            }
        }
        return null;
    }

    private function isVisible(BulkActionInterface $action): bool
    {
        $perm = $action->getRequiredPermission();
        if ($perm === null) {
            return true;
        }
        if (!Permission::isValid($perm)) {
            // Misconfigured action: fail closed (hide it).
            return false;
        }
        return $this->security->isGranted($perm);
    }
}
