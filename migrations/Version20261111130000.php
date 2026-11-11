<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add phishing_domain table for the Phishing.Database list';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE phishing_domain (domain VARCHAR(253) NOT NULL, PRIMARY KEY (domain)) DEFAULT CHARACTER SET utf8');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE phishing_domain');
    }
}
