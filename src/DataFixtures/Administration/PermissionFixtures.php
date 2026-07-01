<?php

declare(strict_types=1);

namespace App\DataFixtures\Administration;

use App\Entity\Administration\Permission;
use App\Security\Permission as Perm;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Mirrors the canonical permission catalog (App\Security\Permission) into
 * the `permission` table. Idempotent: skipped if a row with the same name
 * already exists.
 */
class PermissionFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $repo = $manager->getRepository(Permission::class);

        foreach (Perm::all() as $name) {
            if ($repo->findOneBy(['name' => $name]) === null) {
                $manager->persist(new Permission($name));
            }
        }

        $manager->flush();
    }
}
