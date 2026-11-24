<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retirer les comptes collaborateurs supprimés des listes tout en préservant leurs historiques.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE employee ADD COLUMN deleted_at VARCHAR(25) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE employee DROP COLUMN deleted_at');
    }
}
