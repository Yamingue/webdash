<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260930131153 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, created_at DATETIME NOT NULL, actor_name VARCHAR(120) NOT NULL, "action" VARCHAR(40) NOT NULL, entity_type VARCHAR(40) NOT NULL, entity_id INTEGER DEFAULT NULL, summary CLOB NOT NULL, data CLOB NOT NULL, actor_id INTEGER DEFAULT NULL, domain_id INTEGER DEFAULT NULL, CONSTRAINT FK_F6E1C0F510DAF24A FOREIGN KEY (actor_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_F6E1C0F5115F0EE5 FOREIGN KEY (domain_id) REFERENCES domain (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_F6E1C0F58B8E8428 ON audit_log (created_at)');
        $this->addSql('CREATE INDEX IDX_F6E1C0F510DAF24A ON audit_log (actor_id)');
        $this->addSql('CREATE INDEX IDX_F6E1C0F5115F0EE5 ON audit_log (domain_id)');
        $this->addSql('ALTER TABLE evaluation ADD COLUMN lower_is_better BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE evaluation ADD COLUMN weight DOUBLE PRECISION DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE kpi ADD COLUMN lower_is_better BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE kpi ADD COLUMN weight DOUBLE PRECISION DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE user ADD COLUMN notify_by_email BOOLEAN DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('CREATE TEMPORARY TABLE __temp__evaluation AS SELECT id, week_start, target, score, comment, status, created_at, submitted_at, validated_at, rejection_reason, kpi_id, created_by_id, validated_by_id FROM evaluation');
        $this->addSql('DROP TABLE evaluation');
        $this->addSql('CREATE TABLE evaluation (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, week_start DATE NOT NULL, target DOUBLE PRECISION NOT NULL, score DOUBLE PRECISION DEFAULT NULL, comment CLOB DEFAULT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, submitted_at DATETIME DEFAULT NULL, validated_at DATETIME DEFAULT NULL, rejection_reason CLOB DEFAULT NULL, kpi_id INTEGER NOT NULL, created_by_id INTEGER DEFAULT NULL, validated_by_id INTEGER DEFAULT NULL, CONSTRAINT FK_1323A575F50D1A5E FOREIGN KEY (kpi_id) REFERENCES kpi (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_1323A575B03A8386 FOREIGN KEY (created_by_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_1323A575C69DE5E5 FOREIGN KEY (validated_by_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO evaluation (id, week_start, target, score, comment, status, created_at, submitted_at, validated_at, rejection_reason, kpi_id, created_by_id, validated_by_id) SELECT id, week_start, target, score, comment, status, created_at, submitted_at, validated_at, rejection_reason, kpi_id, created_by_id, validated_by_id FROM __temp__evaluation');
        $this->addSql('DROP TABLE __temp__evaluation');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1323A575F50D1A5E54D29C0C ON evaluation (kpi_id, week_start)');
        $this->addSql('CREATE INDEX IDX_1323A575F50D1A5E ON evaluation (kpi_id)');
        $this->addSql('CREATE INDEX IDX_1323A575B03A8386 ON evaluation (created_by_id)');
        $this->addSql('CREATE INDEX IDX_1323A575C69DE5E5 ON evaluation (validated_by_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__kpi AS SELECT id, code, name, default_target, unit, position, active, domain_id FROM kpi');
        $this->addSql('DROP TABLE kpi');
        $this->addSql('CREATE TABLE kpi (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, code VARCHAR(40) NOT NULL, name VARCHAR(160) NOT NULL, default_target DOUBLE PRECISION NOT NULL, unit VARCHAR(20) DEFAULT NULL, position INTEGER NOT NULL, active BOOLEAN NOT NULL, domain_id INTEGER NOT NULL, CONSTRAINT FK_A0925DD9115F0EE5 FOREIGN KEY (domain_id) REFERENCES domain (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO kpi (id, code, name, default_target, unit, position, active, domain_id) SELECT id, code, name, default_target, unit, position, active, domain_id FROM __temp__kpi');
        $this->addSql('DROP TABLE __temp__kpi');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A0925DD977153098 ON kpi (code)');
        $this->addSql('CREATE INDEX IDX_A0925DD9115F0EE5 ON kpi (domain_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__user AS SELECT id, email, full_name, roles, password FROM "user"');
        $this->addSql('DROP TABLE "user"');
        $this->addSql('CREATE TABLE "user" (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, full_name VARCHAR(120) NOT NULL, roles CLOB NOT NULL, password VARCHAR(255) NOT NULL)');
        $this->addSql('INSERT INTO "user" (id, email, full_name, roles, password) SELECT id, email, full_name, roles, password FROM __temp__user');
        $this->addSql('DROP TABLE __temp__user');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON "user" (email)');
    }
}
