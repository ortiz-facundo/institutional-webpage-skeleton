<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the `state` column to `app_user` and the `account_confirmation_token`
 * table used by the new account-confirmation flow.
 *
 * The state column is `varchar(32)` (room for `USER_STATE_DISABLED` plus
 * headroom) with a CHECK constraint that locks the allowed values to the
 * canonical User::USER_STATE_* constants. Storing the constants' NAMES
 * (rather than shorter forms like 'pending'/'active') makes raw SQL
 * self-documenting and impossible to drift.
 *
 * The UPDATE step sets every existing user to `USER_STATE_ACTIVE`: they
 * all have valid password hashes from the prior registration flow, so
 * they remain log-in-able after this migration runs. Future users start
 * at `USER_STATE_PENDING` (the entity default).
 */
final class Version20260626000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add app_user.state and account_confirmation_token table; mark all existing users as USER_STATE_ACTIVE';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE app_user ADD COLUMN state VARCHAR(32) NOT NULL DEFAULT 'USER_STATE_PENDING'");
        $this->addSql("ALTER TABLE app_user ADD CONSTRAINT app_user_state_check CHECK (state IN ('USER_STATE_PENDING', 'USER_STATE_ACTIVE', 'USER_STATE_DISABLED'))");
        $this->addSql("CREATE INDEX idx_app_user_state ON app_user (state)");

        $this->addSql(<<<'SQL'
            CREATE TABLE account_confirmation_token (
                id BIGINT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                selector VARCHAR(32) NOT NULL,
                hashed_token VARCHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                consumed_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql("CREATE UNIQUE INDEX uniq_token_selector ON account_confirmation_token (selector)");
        $this->addSql("CREATE INDEX idx_token_user ON account_confirmation_token (user_id)");
        $this->addSql("ALTER TABLE account_confirmation_token ADD CONSTRAINT fk_token_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE");

        $this->addSql("UPDATE app_user SET state = 'USER_STATE_ACTIVE' WHERE password IS NOT NULL AND password <> ''");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DROP TABLE IF EXISTS account_confirmation_token");
        $this->addSql("DROP INDEX IF EXISTS idx_app_user_state");
        $this->addSql("ALTER TABLE app_user DROP CONSTRAINT IF EXISTS app_user_state_check");
        $this->addSql("ALTER TABLE app_user DROP COLUMN IF EXISTS state");
    }
}
