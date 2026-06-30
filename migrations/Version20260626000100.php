<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Make app_user.password nullable so Pending users (who haven't picked a
 * password yet) can be persisted without one. The UserStateChecker
 * blocks login for non-Active users regardless, so a Pending user with
 * NULL password cannot authenticate even if they somehow obtained a
 * valid session cookie.
 */
final class Version20260626000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make app_user.password nullable';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ALTER COLUMN password DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // No safe down-migration: existing rows might already have NULL
        // passwords (Pending users). Setting NOT NULL would fail.
    }
}