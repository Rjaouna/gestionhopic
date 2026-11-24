<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124144500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enregistrer l’identité visuelle et le thème de l’application.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_appearance (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            name VARCHAR(48) NOT NULL,
            suffix VARCHAR(12) NOT NULL,
            theme VARCHAR(16) NOT NULL,
            logo_version VARCHAR(64) DEFAULT NULL,
            logo_mime VARCHAR(20) DEFAULT NULL,
            logo_content BLOB DEFAULT NULL
        )');
        $this->addSql("INSERT INTO app_appearance (id, name, suffix, theme) VALUES (1, 'tempo', 'RH', 'tempo')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_appearance');
    }
}
