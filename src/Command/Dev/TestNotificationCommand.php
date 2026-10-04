<?php

declare(strict_types=1);

namespace App\Command\Dev;

use App\Repository\Administration\UserRepository;
use App\Service\Notification\CreateNotification;
use App\Security\Permission;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(name: 'app:dev:test-notification', description: '[DEV ONLY] Create an inbox notification and queue subscribed-device pushes.')]
final class TestNotificationCommand extends Command
{
    public function __construct(private readonly CreateNotification $creator, private readonly UserRepository $users, private readonly KernelInterface $kernel) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Recipient email');
        $this->addOption('event', null, InputOption::VALUE_REQUIRED, 'Optional stable event key to demonstrate deduplication');
        $this->addOption('users', null, InputOption::VALUE_NONE, 'Send an emoji notification linking to the Users page');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->kernel->getEnvironment() === 'prod') { $output->writeln('<error>This command is dev-only.</error>'); return Command::FAILURE; }
        $user = $this->users->findOneByEmail($input->getArgument('email'));
        if ($user === null) { $output->writeln('<error>Recipient not found.</error>'); return Command::FAILURE; }
        $users = $input->getOption('users');
        if ($users && !in_array(Permission::PERMISSION_MANAGE_USERS, $user->getRoles(), true)) {
            $output->writeln('<error>The recipient cannot access the Users page.</error>');
            return Command::FAILURE;
        }
        $notification = $this->creator->execute(
            $user,
            $users ? 'dev.users' : 'dev.test',
            $users ? 'app.notification.test.users_title' : 'app.notification.test.title',
            $users ? 'app.notification.test.users_body' : 'app.notification.test.body',
            ['%name%' => $user->getName() ?? $user->getEmail()],
            destinationRoute: $users ? 'administration_user_list' : 'backoffice_dashboard',
            deduplicationKey: $input->getOption('event'),
            requiredPermission: $users ? Permission::PERMISSION_MANAGE_USERS : null,
        );
        $output->writeln(sprintf('Notification #%d saved. Run app:notifications:process -v to send queued pushes.', $notification->getId()));
        return Command::SUCCESS;
    }
}
