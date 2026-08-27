<?php

declare(strict_types=1);

namespace App\DataFixtures\Administration;

use App\Entity\Administration\User;
use App\Repository\Administration\RoleRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Dev seed users. Two accounts are created:
 *   - `test@skeleton.local` / `test` — ROLE_ADMIN. The original test
 *     user; safe to load in dev and test.
 *   - `manager@skeleton.local` / `manager` — ROLE_MANAGER. Smoke-test
 *     the manager tier: can create users and edit roles, but cannot
 *     grant admin permissions to anyone (enforced by the
 *     OnlyAdminCanGrantAdminRole / OnlyAdminCanGrantAdminPermission
 *     validators on UserType and RoleType).
 *
 * Both emails use the skeleton.local domain so downstream projects can
 * safely re-seed without colliding with production addresses. The
 * fixtures bundle is only registered for dev/test in bundles.php, so
 * neither user can leak into prod. Override per-project by editing this
 * fixture or by replacing the users with the project's own rows in
 * /admin/users.
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

        $this->ensureUser($manager, $repo, email: 'test@skeleton.local', name: 'Test User', password: 'test', roleName: 'ROLE_ADMIN');
        $this->ensureUser($manager, $repo, email: 'manager@skeleton.local', name: 'Test Manager', password: 'manager', roleName: 'ROLE_MANAGER');

        $manager->flush();
    }

    private function ensureUser(
        ObjectManager $manager,
        ObjectRepository $repo,
        string $email,
        string $name,
        string $password,
        string $roleName,
    ): void {
        if ($repo->findOneBy(['email' => $email]) !== null) {
            return;
        }

        $user = new User();
        $user->setEmail($email);
        $user->setName($name);
        $user->setPassword($this->hasher->hashPassword($user, $password));

        // Must be Active so login works in dev. The migration also
        // back-fills existing users to Active, but fresh dev installs
        // run fixtures after migrations, so we set it here defensively.
        $user->setState(User::USER_STATE_ACTIVE);

        $role = $this->roles->findOneBy(['name' => $roleName]);
        if ($role !== null) {
            $user->addRole($role);
        }

        $manager->persist($user);
    }
}
