<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Security\Permission;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seeds the permission table with the canonical catalog defined in
 * App\Security\Permission. The constant names and values are identical,
 * so a typo in either side fails fast.
 */
final class Version20260619042400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed the permission catalog from App\Security\Permission';
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
