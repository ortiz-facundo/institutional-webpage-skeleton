<?php

declare(strict_types=1);

namespace App\Command\Notification;

use App\Service\Notification\SendPendingNotifications;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:notifications:process', description: 'Process a bounded batch of pending notification deliveries (safe for cron).')]
final class ProcessNotificationsCommand extends Command
{
    public function __construct(private readonly SendPendingNotifications $sender, #[Autowire('%kernel.project_dir%')] private readonly string $projectDir) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('seconds', null, InputOption::VALUE_REQUIRED, 'Processing budget (15–50 seconds)', '45');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum deliveries (1–100)', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $seconds = filter_var($input->getOption('seconds'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 15, 'max_range' => 50]]);
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($seconds === false || $limit === false) { $output->writeln('<error>Invalid processing limits.</error>'); return Command::INVALID; }
        $lock = fopen($this->projectDir.'/var/notification-worker.lock', 'c');
        if ($lock === false) { throw new \RuntimeException('Cannot open notification worker lock.'); }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                if ($output->isVerbose()) { $output->writeln('Another notification worker is running; skipped.'); }
                return Command::SUCCESS;
            }
            $result = $this->sender->execute($seconds, $limit);
            if ($output->isVerbose()) { $output->writeln(json_encode($result, JSON_THROW_ON_ERROR)); }
            // Failures generate cron email without leaking keys or endpoints.
            if ($result['failed'] > 0) { $output->writeln(sprintf('<error>%d deliveries permanently failed; inspect notification_delivery.</error>', $result['failed'])); }
            return $result['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
