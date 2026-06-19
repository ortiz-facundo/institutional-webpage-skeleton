<?php

declare(strict_types=1);

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Orchestrates the backoffice fixtures. Order:
 *   1. PermissionFixtures  (inserts the catalog)
 *   2. RoleFixtures        (references permission catalog)
 *   3. UserFixtures        (references roles)
 *
 * This class is intentionally empty of business data — it exists so the
 * other three fixtures run in the right order on `doctrine:fixtures:load`.
 */
class AppFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
    }

    public function getDependencies(): array
    {
        return [
            PermissionFixtures::class,
            RoleFixtures::class,
            UserFixtures::class,
        ];
    }
}
