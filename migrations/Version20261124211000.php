<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124211000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajouter le catalogue EBP et l’état persistant de sa synchronisation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE ebp_connection (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            source_url CLOB NOT NULL, photo_path CLOB NOT NULL, state VARCHAR(16) NOT NULL,
            attempts INTEGER NOT NULL, next_attempt_at INTEGER NOT NULL, lease VARCHAR(32) DEFAULT NULL,
            leased_until INTEGER NOT NULL, message VARCHAR(255) NOT NULL,
            last_import_at INTEGER DEFAULT NULL, last_count INTEGER NOT NULL)");
        $this->addSql("INSERT INTO ebp_connection (id, source_url, photo_path, state, attempts,
            next_attempt_at, leased_until, message, last_count)
            VALUES (1, 'https://hopic.pro/ebpsynch/items.csv', '', 'idle', 0, 0, 0, '', 0)");
        $this->addSql("CREATE TABLE ebp_article (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            unique_id VARCHAR(100) NOT NULL, reference VARCHAR(255) NOT NULL, description CLOB NOT NULL,
            stock VARCHAR(32) NOT NULL, counted_quantity VARCHAR(32) DEFAULT '0' NOT NULL,
            brand VARCHAR(255) NOT NULL, category VARCHAR(255) NOT NULL, web_code VARCHAR(255) NOT NULL,
            search_text CLOB NOT NULL, source_fields CLOB NOT NULL, present BOOLEAN NOT NULL)");
        $this->addSql('CREATE UNIQUE INDEX ebp_article_unique ON ebp_article (unique_id)');
        $this->addSql('CREATE INDEX ebp_article_reference ON ebp_article (present, reference)');
        $this->addSql('CREATE INDEX ebp_article_brand ON ebp_article (present, brand)');
        $this->addSql('CREATE INDEX ebp_article_category ON ebp_article (present, category)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Le catalogue et les quantités doivent être conservés.');
    }
}
