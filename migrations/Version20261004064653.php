<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Add reversible archive state without changing existing read/delivery history. */
final class Version20261004064653 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add notification archive timestamp and recipient/archive index';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification ADD archived_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX notification_archive_idx ON notification (recipient_id, archived_at, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX notification_archive_idx ON notification');
        $this->addSql('ALTER TABLE notification DROP archived_at');
    }
}
