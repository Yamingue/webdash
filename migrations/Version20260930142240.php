<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260930142240 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__evaluation AS SELECT id, week_start, target, score, comment, status, created_at, submitted_at, validated_at, rejection_reason, kpi_id, created_by_id, validated_by_id, lower_is_better, weight FROM evaluation');
        $this->addSql('DROP TABLE evaluation');
        $this->addSql('CREATE TABLE evaluation (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, week_start DATE NOT NULL, target DOUBLE PRECISION NOT NULL, score DOUBLE PRECISION DEFAULT NULL, comment CLOB DEFAULT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, submitted_at DATETIME DEFAULT NULL, validated_at DATETIME DEFAULT NULL, rejection_reason CLOB DEFAULT NULL, kpi_id INTEGER NOT NULL, created_by_id INTEGER DEFAULT NULL, validated_by_id INTEGER DEFAULT NULL, lower_is_better BOOLEAN DEFAULT 0 NOT NULL, weight DOUBLE PRECISION DEFAULT 1 NOT NULL, version INTEGER DEFAULT 1 NOT NULL, CONSTRAINT FK_1323A575F50D1A5E FOREIGN KEY (kpi_id) REFERENCES kpi (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_1323A575B03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_1323A575C69DE5E5 FOREIGN KEY (validated_by_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO evaluation (id, week_start, target, score, comment, status, created_at, submitted_at, validated_at, rejection_reason, kpi_id, created_by_id, validated_by_id, lower_is_better, weight) SELECT id, week_start, target, score, comment, status, created_at, submitted_at, validated_at, rejection_reason, kpi_id, created_by_id, validated_by_id, lower_is_better, weight FROM __temp__evaluation');
        $this->addSql('DROP TABLE __temp__evaluation');
        $this->addSql('CREATE INDEX IDX_1323A575C69DE5E5 ON evaluation (validated_by_id)');
        $this->addSql('CREATE INDEX IDX_1323A575B03A8386 ON evaluation (created_by_id)');
        $this->addSql('CREATE INDEX IDX_1323A575F50D1A5E ON evaluation (kpi_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1323A575F50D1A5E54D29C0C ON evaluation (kpi_id, week_start)');
        $this->addSql('CREATE INDEX IDX_1323A57554D29C0C ON evaluation (week_start)');
        $this->addSql('CREATE INDEX IDX_1323A5757B00651C ON evaluation (status)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__evaluation AS SELECT id, week_start, target, lower_is_better, weight, score, comment, status, created_at, submitted_at, validated_at, rejection_reason, kpi_id, created_by_id, validated_by_id FROM evaluation');
        $this->addSql('DROP TABLE evaluation');
        $this->addSql('CREATE TABLE evaluation (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, week_start DATE NOT NULL, target DOUBLE PRECISION NOT NULL, lower_is_better BOOLEAN DEFAULT 0 NOT NULL, weight DOUBLE PRECISION DEFAULT 1 NOT NULL, score DOUBLE PRECISION DEFAULT NULL, comment CLOB DEFAULT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, submitted_at DATETIME DEFAULT NULL, validated_at DATETIME DEFAULT NULL, rejection_reason CLOB DEFAULT NULL, kpi_id INTEGER NOT NULL, created_by_id INTEGER DEFAULT NULL, validated_by_id INTEGER DEFAULT NULL, CONSTRAINT FK_1323A575F50D1A5E FOREIGN KEY (kpi_id) REFERENCES kpi (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_1323A575B03A8386 FOREIGN KEY (created_by_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_1323A575C69DE5E5 FOREIGN KEY (validated_by_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO evaluation (id, week_start, target, lower_is_better, weight, score, comment, status, created_at, submitted_at, validated_at, rejection_reason, kpi_id, created_by_id, validated_by_id) SELECT id, week_start, target, lower_is_better, weight, score, comment, status, created_at, submitted_at, validated_at, rejection_reason, kpi_id, created_by_id, validated_by_id FROM __temp__evaluation');
        $this->addSql('DROP TABLE __temp__evaluation');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1323A575F50D1A5E54D29C0C ON evaluation (kpi_id, week_start)');
        $this->addSql('CREATE INDEX IDX_1323A575F50D1A5E ON evaluation (kpi_id)');
        $this->addSql('CREATE INDEX IDX_1323A575B03A8386 ON evaluation (created_by_id)');
        $this->addSql('CREATE INDEX IDX_1323A575C69DE5E5 ON evaluation (validated_by_id)');
    }
}
