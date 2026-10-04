<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Persistent inbox, browser subscriptions, and the bounded cron delivery queue. */
final class Version20261004022937 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add notification inbox, Chrome subscriptions, and per-device delivery queue';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notification (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(100) NOT NULL, event_key VARCHAR(64) NOT NULL, title_key VARCHAR(255) NOT NULL, body_key VARCHAR(255) NOT NULL, translation_parameters JSON NOT NULL, destination_route VARCHAR(150) NOT NULL, destination_parameters JSON NOT NULL, required_permission VARCHAR(150) DEFAULT NULL, created_at DATETIME NOT NULL, read_at DATETIME DEFAULT NULL, recipient_id INT NOT NULL, INDEX IDX_BF5476CAE92F8F78 (recipient_id), INDEX notification_inbox_idx (recipient_id, created_at), INDEX notification_unread_idx (recipient_id, read_at), UNIQUE INDEX notification_event_unique (recipient_id, event_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE notification_delivery (id INT AUTO_INCREMENT NOT NULL, channel VARCHAR(30) NOT NULL, status VARCHAR(30) NOT NULL, attempts INT NOT NULL, next_attempt_at DATETIME NOT NULL, accepted_at DATETIME DEFAULT NULL, last_error VARCHAR(500) DEFAULT NULL, notification_id INT NOT NULL, push_subscription_id INT DEFAULT NULL, INDEX IDX_6291D4B5EF1A9D84 (notification_id), INDEX IDX_6291D4B53B3096E6 (push_subscription_id), INDEX notification_delivery_due_idx (status, next_attempt_at), UNIQUE INDEX notification_device_unique (notification_id, push_subscription_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE push_subscription (id INT AUTO_INCREMENT NOT NULL, endpoint LONGTEXT NOT NULL, endpoint_hash VARCHAR(64) NOT NULL, public_key VARCHAR(100) NOT NULL, auth_secret VARCHAR(50) NOT NULL, device_label VARCHAR(120) NOT NULL, enabled TINYINT NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_562830F3867498CF (endpoint_hash), INDEX IDX_562830F3A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAE92F8F78 FOREIGN KEY (recipient_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notification_delivery ADD CONSTRAINT FK_6291D4B5EF1A9D84 FOREIGN KEY (notification_id) REFERENCES notification (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notification_delivery ADD CONSTRAINT FK_6291D4B53B3096E6 FOREIGN KEY (push_subscription_id) REFERENCES push_subscription (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE push_subscription ADD CONSTRAINT FK_562830F3A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CAE92F8F78');
        $this->addSql('ALTER TABLE notification_delivery DROP FOREIGN KEY FK_6291D4B5EF1A9D84');
        $this->addSql('ALTER TABLE notification_delivery DROP FOREIGN KEY FK_6291D4B53B3096E6');
        $this->addSql('ALTER TABLE push_subscription DROP FOREIGN KEY FK_562830F3A76ED395');
        $this->addSql('DROP TABLE notification');
        $this->addSql('DROP TABLE notification_delivery');
        $this->addSql('DROP TABLE push_subscription');
    }
}
