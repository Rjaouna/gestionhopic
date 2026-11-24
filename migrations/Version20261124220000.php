<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajouter les campagnes d’inventaire, leurs affectations et le comptage des articles.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE inventory_campaign (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(120) NOT NULL, started_at INTEGER NOT NULL, closed_at INTEGER DEFAULT NULL)');
        $this->addSql('CREATE TABLE inventory_assignment (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, brand VARCHAR(255) DEFAULT NULL, category VARCHAR(255) DEFAULT NULL, assigned_at INTEGER NOT NULL, closed_at INTEGER DEFAULT NULL, campaign_id INTEGER NOT NULL, employee_id INTEGER NOT NULL, CONSTRAINT FK_732B1139F639F774 FOREIGN KEY (campaign_id) REFERENCES inventory_campaign (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_732B11398C03F15C FOREIGN KEY (employee_id) REFERENCES employee (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_732B1139F639F774 ON inventory_assignment (campaign_id)');
        $this->addSql('CREATE INDEX IDX_732B11398C03F15C ON inventory_assignment (employee_id)');
        $this->addSql('CREATE TABLE inventory_line (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, unique_id VARCHAR(100) NOT NULL, reference VARCHAR(255) NOT NULL, description CLOB NOT NULL, brand VARCHAR(255) NOT NULL, category VARCHAR(255) NOT NULL, web_code VARCHAR(255) NOT NULL, search_text CLOB NOT NULL, expected_quantity VARCHAR(32) NOT NULL, counted_quantity VARCHAR(32) DEFAULT NULL, completed BOOLEAN NOT NULL, version INTEGER NOT NULL, campaign_id INTEGER NOT NULL, assignment_id INTEGER DEFAULT NULL, CONSTRAINT FK_9BB27BD8F639F774 FOREIGN KEY (campaign_id) REFERENCES inventory_campaign (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_9BB27BD8D19302F8 FOREIGN KEY (assignment_id) REFERENCES inventory_assignment (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX inventory_line_campaign_article ON inventory_line (campaign_id, unique_id)');
        $this->addSql('CREATE INDEX IDX_9BB27BD8F639F774 ON inventory_line (campaign_id)');
        $this->addSql('CREATE INDEX IDX_9BB27BD8D19302F8 ON inventory_line (assignment_id)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les campagnes d’inventaire et leurs comptages constituent un historique.');
    }
}
