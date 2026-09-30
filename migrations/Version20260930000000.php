<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/** Remove the non-baseline ROLE_MANAGER while preserving affected users' basic access. */
final class Version20260930000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove ROLE_MANAGER and retain ROLE_USER access for its assigned users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO user_role (user_id, role_id)
            SELECT manager_assignment.user_id, user_role_baseline.id
            FROM user_role manager_assignment
            INNER JOIN role manager_role ON manager_role.id = manager_assignment.role_id AND manager_role.name = 'ROLE_MANAGER'
            INNER JOIN role user_role_baseline ON user_role_baseline.name = 'ROLE_USER'
            SQL);
        $this->addSql("DELETE FROM role WHERE name = 'ROLE_MANAGER'");
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Removed ROLE_MANAGER assignments cannot be reconstructed safely.');
    }
}
