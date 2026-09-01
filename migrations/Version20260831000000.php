<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add `app_user.avatar_filename` / `avatar_size` / `avatar_updated_at`
 * (all nullable), backing the self-service profile-photo upload managed
 * by vich/uploader-bundle (see config/packages/vich_uploader.yaml and the
 * `avatar` mapping on App\Entity\Administration\User).
 *
 * `avatar_filename` is the generated stored filename (never the client's
 * original name), `avatar_size` its byte size, and `avatar_updated_at` a
 * touch column Doctrine needs dirty to detect an avatar change on an
 * already-persisted row (see User::setAvatarFile()'s docblock).
 *
 * Existing rows are left NULL — the UI falls back to the initials
 * placeholder (_avatar.html.twig) until a user uploads a photo via
 * /account/profile-settings/edit-profile.
 */
final class Version20260831000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add app_user.avatar_filename/avatar_size/avatar_updated_at (nullable) for self-service profile photo';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD COLUMN avatar_filename VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD COLUMN avatar_size INT DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD COLUMN avatar_updated_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP COLUMN avatar_filename');
        $this->addSql('ALTER TABLE app_user DROP COLUMN avatar_size');
        $this->addSql('ALTER TABLE app_user DROP COLUMN avatar_updated_at');
    }
}
