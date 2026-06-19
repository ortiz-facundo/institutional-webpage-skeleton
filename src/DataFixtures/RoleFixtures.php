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
 * ROLE_USER (view backoffice only). Idempotent: skipped if the role
 * already exists.
 */
class RoleFixtures extends Fixture
{
    public function __construct(private readonly PermissionRepository $permissions)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $repo = $manager->getRepository(Role::class);

        $admin = $repo->findOneBy(['name' => 'ROLE_ADMIN']);
        if ($admin === null) {
            $admin = new Role('ROLE_ADMIN');
            foreach (Perm::all() as $name) {
                $p = $this->permissions->findOneByName($name);
                if ($p !== null) {
                    $admin->addPermission($p);
                }
            }
            $manager->persist($admin);
        }

        $user = $repo->findOneBy(['name' => 'ROLE_USER']);
        if ($user === null) {
            $user = new Role('ROLE_USER');
            $view = $this->permissions->findOneByName(Perm::PERMISSION_VIEW_BACKOFFICE);
            if ($view !== null) {
                $user->addPermission($view);
            }
            $manager->persist($user);
        }

        $manager->flush();
    }
}
