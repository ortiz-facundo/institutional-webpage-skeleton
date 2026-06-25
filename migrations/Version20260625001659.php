<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Security\Permission;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seeds any newly-added permissions from App\Security\Permission into the
 * permission table. Idempotent — re-running this migration (or the original
 * permission-seed migration) is a no-op once the rows exist.
 *
 * When adding a new permission constant, append it to Permission::all() and
 * ship a new migration that follows this pattern.
 */
final class Version20260625001659 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed any new entries from App\\Security\\Permission into the permission table';
    }

    public function up(Schema $schema): void
    {
        foreach (Permission::all() as $name) {
            $this->addSql(
                "INSERT INTO permission (name) VALUES (:name) ON CONFLICT (name) DO NOTHING",
                ['name' => $name]
            );
        }
    }

    public function down(Schema $schema): void
    {
        // Best-effort cleanup: only deletes rows whose name is still a
        // canonical catalog entry, leaving any future-added rows alone.
        foreach (Permission::all() as $name) {
            $this->addSql("DELETE FROM permission WHERE name = :name", ['name' => $name]);
        }
    }
}