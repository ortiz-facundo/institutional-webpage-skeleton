<?php

declare(strict_types=1);

namespace App\DataFixtures\Administration;

use App\Entity\Administration\User;
use App\Repository\Administration\RoleRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Test user: test@skeleton.local / password: test / role: ROLE_ADMIN.
 *
 * Safe to load in dev and test; should never be loaded in prod (the
 * fixtures bundle is only registered for dev/test in bundles.php).
 *
 * The email uses the skeleton.local domain so downstream projects can
 * safely re-seed without colliding with production addresses. Override
 * per-project by editing this fixture or by replacing the user with
 * the project's own row in /admin/users.
 */
class UserFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly RoleRepository $roles,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $repo = $manager->getRepository(User::class);

        if ($repo->findOneBy(['email' => 'test@skeleton.local']) !== null) {
            return;
        }

        $user = new User();
        $user->setEmail('test@skeleton.local');
        $user->setName('Test User');

        $hashed = $this->hasher->hashPassword($user, 'test');
        $user->setPassword($hashed);

        // The test user must be Active so login works in dev. The
        // migration also back-fills existing users to Active, but
        // fresh dev installs run fixtures after migrations, so we set
        // it here defensively.
        $user->setState(User::USER_STATE_ACTIVE);

        $admin = $this->roles->findOneBy(['name' => 'ROLE_ADMIN']);
        if ($admin !== null) {
            $user->addRole($admin);
        }

        $manager->persist($user);
        $manager->flush();
    }
}
