<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260930110136 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE domain (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(120) NOT NULL, slug VARCHAR(120) NOT NULL, icon VARCHAR(100) NOT NULL, position INTEGER NOT NULL, active BOOLEAN NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A7A91E0B989D9B62 ON domain (slug)');
        $this->addSql('CREATE TABLE evaluation (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, week_start DATE NOT NULL, target DOUBLE PRECISION NOT NULL, score DOUBLE PRECISION DEFAULT NULL, comment CLOB DEFAULT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, submitted_at DATETIME DEFAULT NULL, validated_at DATETIME DEFAULT NULL, rejection_reason CLOB DEFAULT NULL, kpi_id INTEGER NOT NULL, created_by_id INTEGER DEFAULT NULL, validated_by_id INTEGER DEFAULT NULL, CONSTRAINT FK_1323A575F50D1A5E FOREIGN KEY (kpi_id) REFERENCES kpi (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_1323A575B03A8386 FOREIGN KEY (created_by_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_1323A575C69DE5E5 FOREIGN KEY (validated_by_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1323A575F50D1A5E54D29C0C ON evaluation (kpi_id, week_start)');
        $this->addSql('CREATE INDEX IDX_1323A575F50D1A5E ON evaluation (kpi_id)');
        $this->addSql('CREATE INDEX IDX_1323A575B03A8386 ON evaluation (created_by_id)');
        $this->addSql('CREATE INDEX IDX_1323A575C69DE5E5 ON evaluation (validated_by_id)');
        $this->addSql('CREATE TABLE kpi (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, code VARCHAR(40) NOT NULL, name VARCHAR(160) NOT NULL, default_target DOUBLE PRECISION NOT NULL, unit VARCHAR(20) DEFAULT NULL, position INTEGER NOT NULL, active BOOLEAN NOT NULL, domain_id INTEGER NOT NULL, CONSTRAINT FK_A0925DD9115F0EE5 FOREIGN KEY (domain_id) REFERENCES domain (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A0925DD977153098 ON kpi (code)');
        $this->addSql('CREATE INDEX IDX_A0925DD9115F0EE5 ON kpi (domain_id)');
        $this->addSql('CREATE TABLE membership (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, role VARCHAR(20) NOT NULL, user_id INTEGER NOT NULL, domain_id INTEGER NOT NULL, CONSTRAINT FK_86FFD285A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_86FFD285115F0EE5 FOREIGN KEY (domain_id) REFERENCES domain (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_86FFD285A76ED395115F0EE5 ON membership (user_id, domain_id)');
        $this->addSql('CREATE INDEX IDX_86FFD285A76ED395 ON membership (user_id)');
        $this->addSql('CREATE INDEX IDX_86FFD285115F0EE5 ON membership (domain_id)');
        $this->addSql('CREATE TABLE "user" (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, full_name VARCHAR(120) NOT NULL, roles CLOB NOT NULL, password VARCHAR(255) NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON "user" (email)');
        $this->addSql('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, body CLOB NOT NULL, headers CLOB NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE domain');
        $this->addSql('DROP TABLE evaluation');
        $this->addSql('DROP TABLE kpi');
        $this->addSql('DROP TABLE membership');
        $this->addSql('DROP TABLE "user"');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
