<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add `app_user.locale` (nullable VARCHAR(5)).
 *
 * Holds the authenticated user's preferred UI language. `null` means
 * "use the system default" (Spanish for unauthenticated requests, see
 * LocaleResolver). The system default lives in the application code, not
 * in this column, so changing the project default is a code change — not
 * a data migration.
 *
 * Existing rows are intentionally left NULL so LocaleResolver falls
 * through to the system default for them. New rows created by
 * UserFixtures / admin create-flow also default to NULL, but the Locale
 * switcher in /account/profile-settings writes 'en' or 'es' explicitly
 * the first time the user picks.
 */
final class Version20260701000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add app_user.locale (nullable) for per-user UI language preference';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE app_user ADD COLUMN locale VARCHAR(5) DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP COLUMN locale');
    }
}
