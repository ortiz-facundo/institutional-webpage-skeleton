<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use App\Repository\RoleRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Test user: test@nova-argentum.local / password: test / role: ROLE_ADMIN.
 * Safe to load in dev and test; should never be loaded in prod (the
 * fixtures bundle is only registered for dev/test in bundles.php).
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

        if ($repo->findOneBy(['email' => 'test@nova-argentum.local']) !== null) {
            return;
        }

        $user = new User();
        $user->setEmail('test@nova-argentum.local');
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
