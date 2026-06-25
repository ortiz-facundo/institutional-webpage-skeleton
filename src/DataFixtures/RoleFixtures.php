<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Permission;
use App\Entity\Role;
use App\Repository\PermissionRepository;
use App\Security\Permission as Perm;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Creates the two baseline roles: ROLE_ADMIN (all permissions) and
 * ROLE_USER (view backoffice only). Idempotent: if a role already exists
 * it is updated in place — any newly-added permissions are wired up, so
 * shipping a new permission constant doesn't require manual DB work.
 */
class RoleFixtures extends Fixture
{
    public function __construct(private readonly PermissionRepository $permissions)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $repo = $manager->getRepository(Role::class);

        $admin = $repo->findOneBy(['name' => 'ROLE_ADMIN']) ?? new Role('ROLE_ADMIN');
        $this->syncPermissions($admin, Perm::all());
        if ($manager->contains($admin) === false) {
            $manager->persist($admin);
        }

        $user = $repo->findOneBy(['name' => 'ROLE_USER']) ?? new Role('ROLE_USER');
        $this->syncPermissions($user, [Perm::PERMISSION_VIEW_BACKOFFICE]);
        if ($manager->contains($user) === false) {
            $manager->persist($user);
        }

        $manager->flush();
    }

    /**
     * Add every permission in `$wanted` that the role doesn't already have.
     * Pass Perm::all() for ROLE_ADMIN, a single-element list for ROLE_USER.
     */
    private function syncPermissions(Role $role, array $wanted): void
    {
        foreach ($wanted as $name) {
            $p = $this->permissions->findOneByName($name);
            if ($p !== null && !$role->getPermissions()->contains($p)) {
                $role->addPermission($p);
            }
        }
    }
}
