<?php

declare(strict_types=1);

namespace App\Command\Deploy;

use App\Entity\Administration\Permission as PermissionEntity;
use App\Entity\Administration\Role;
use App\Entity\Administration\User;
use App\Repository\Administration\RoleRepository;
use App\Repository\Administration\UserRepository;
use App\Security\Permission as PermissionCatalog;
use App\Service\Security\UserLifecycle\UserStateTransitionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Seed the two baseline roles and optionally the first production admin. */
#[AsCommand(
    name: 'app:deploy:seed-prod',
    description: 'Idempotently seed ROLE_ADMIN, ROLE_USER, and an optional initial admin user.',
)]
final class SeedProdCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RoleRepository $roles,
        private readonly UserRepository $users,
        private readonly UserStateTransitionService $transitions,
        #[Autowire(service: 'monolog.logger.audit')]
        private readonly LoggerInterface $auditLogger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Admin email (defaults to PROD_ADMIN_EMAIL).')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Admin display name (defaults to PROD_ADMIN_NAME or the email).')
            ->addOption('skip-admin', null, InputOption::VALUE_NONE, 'Only seed the baseline roles.')
            ->addOption('skip-roles', null, InputOption::VALUE_NONE, 'Only seed the admin user; ROLE_ADMIN must already exist.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            if (!$input->getOption('skip-roles')) {
                $this->seedBaselineRoles($io);
            }

            if (!$input->getOption('skip-admin')) {
                $email = $this->optionOrEnv($input, 'email', 'PROD_ADMIN_EMAIL');
                if ($email === null) {
                    $io->warning('No admin email provided; skipping admin user creation.');
                    $io->writeln('Set PROD_ADMIN_EMAIL or pass --email=admin@example.com to create the first admin.');
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $io->error(sprintf('Invalid admin email address: "%s".', $email));
                    return Command::FAILURE;
                } else {
                    $name = $this->optionOrEnv($input, 'name', 'PROD_ADMIN_NAME') ?? $email;
                    $this->seedAdmin($io, $email, $name);
                }
            }
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function seedBaselineRoles(SymfonyStyle $io): void
    {
        $permissionNames = array_values(array_unique([
            ...PermissionCatalog::all(),
            PermissionCatalog::PERMISSION_VIEW_BACKOFFICE,
        ]));
        $permissions = [];
        foreach ($permissionNames as $name) {
            $permission = $this->entityManager->getRepository(PermissionEntity::class)->findOneBy(['name' => $name]);
            if ($permission === null) {
                throw new \RuntimeException(sprintf('Permission "%s" is missing; run database migrations before seeding.', $name));
            }
            $permissions[$name] = $permission;
        }

        $admin = $this->roles->findOneByName('ROLE_ADMIN') ?? new Role('ROLE_ADMIN');
        $user = $this->roles->findOneByName('ROLE_USER') ?? new Role('ROLE_USER');

        foreach (PermissionCatalog::all() as $name) {
            $admin->addPermission($permissions[$name]);
        }
        $user->addPermission($permissions[PermissionCatalog::PERMISSION_VIEW_BACKOFFICE]);

        $this->entityManager->persist($admin);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $io->success('Baseline roles ROLE_ADMIN and ROLE_USER are present and synchronized.');
    }

    private function seedAdmin(SymfonyStyle $io, string $email, string $name): void
    {
        $existing = $this->users->findOneByEmail($email);
        if ($existing !== null) {
            $io->writeln(sprintf('Admin user "%s" already exists (state: %s).', $email, $existing->getState()));
            if ($existing->isPending()) {
                $this->transitions->resendConfirmation($existing);
                $io->success('A fresh confirmation email was sent to the existing pending admin.');
            }
            return;
        }

        $adminRole = $this->roles->findOneByName('ROLE_ADMIN');
        if ($adminRole === null) {
            throw new \RuntimeException('ROLE_ADMIN is missing; run without --skip-roles to seed it first.');
        }

        $user = new User();
        $user->setEmail($email);
        $user->setName($name);
        $user->addRole($adminRole);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->transitions->resendConfirmation($user);
        $this->auditLogger->info('user.deploy.seed_admin', [
            'user_id' => $user->getId(),
            'user_email' => $user->getEmail(),
            'source' => 'deploy',
        ]);
        $io->success(sprintf('Created pending admin "%s" and sent the confirmation email.', $email));
    }

    private function optionOrEnv(InputInterface $input, string $option, string $envVar): ?string
    {
        $value = $input->getOption($option);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        $env = $_ENV[$envVar] ?? $_SERVER[$envVar] ?? null;
        return is_string($env) && trim($env) !== '' ? trim($env) : null;
    }
}
