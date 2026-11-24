<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261124122855 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE audit_entry (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, message VARCHAR(220) NOT NULL, created_at VARCHAR(25) NOT NULL, actor_id INTEGER NOT NULL, CONSTRAINT FK_2C6AF98710DAF24A FOREIGN KEY (actor_id) REFERENCES employee (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_2C6AF98710DAF24A ON audit_entry (actor_id)');
        $this->addSql('CREATE TABLE balance (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, units INTEGER NOT NULL, employee_id INTEGER NOT NULL, counter_type_id INTEGER NOT NULL, CONSTRAINT FK_ACF41FFE8C03F15C FOREIGN KEY (employee_id) REFERENCES employee (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_ACF41FFE4B27D0B2 FOREIGN KEY (counter_type_id) REFERENCES counter_type (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ACF41FFE8C03F15C4B27D0B2 ON balance (employee_id, counter_type_id)');
        $this->addSql('CREATE INDEX IDX_ACF41FFE8C03F15C ON balance (employee_id)');
        $this->addSql('CREATE INDEX IDX_ACF41FFE4B27D0B2 ON balance (counter_type_id)');
        $this->addSql('CREATE TABLE balance_movement (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, old_units INTEGER NOT NULL, new_units INTEGER NOT NULL, kind VARCHAR(30) NOT NULL, reason VARCHAR(500) NOT NULL, created_at VARCHAR(25) NOT NULL, employee_id INTEGER NOT NULL, counter_type_id INTEGER NOT NULL, actor_id INTEGER NOT NULL, leave_request_id INTEGER DEFAULT NULL, CONSTRAINT FK_DF5D24478C03F15C FOREIGN KEY (employee_id) REFERENCES employee (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_DF5D24474B27D0B2 FOREIGN KEY (counter_type_id) REFERENCES counter_type (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_DF5D244710DAF24A FOREIGN KEY (actor_id) REFERENCES employee (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_DF5D2447F2E1C15D FOREIGN KEY (leave_request_id) REFERENCES leave_request (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_DF5D24478C03F15C ON balance_movement (employee_id)');
        $this->addSql('CREATE INDEX IDX_DF5D24474B27D0B2 ON balance_movement (counter_type_id)');
        $this->addSql('CREATE INDEX IDX_DF5D244710DAF24A ON balance_movement (actor_id)');
        $this->addSql('CREATE INDEX IDX_DF5D2447F2E1C15D ON balance_movement (leave_request_id)');
        $this->addSql('CREATE TABLE counter_type (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(100) NOT NULL, color VARCHAR(7) NOT NULL, active BOOLEAN NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C3538E4E5E237E06 ON counter_type (name)');
        $this->addSql('CREATE TABLE department (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(100) NOT NULL, description VARCHAR(255) NOT NULL, color VARCHAR(7) NOT NULL, active BOOLEAN NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CD1DE18A5E237E06 ON department (name)');
        $this->addSql('CREATE TABLE employee (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, email VARCHAR(180) NOT NULL, code VARCHAR(30) NOT NULL, job VARCHAR(120) NOT NULL, phone VARCHAR(40) NOT NULL, password VARCHAR(255) NOT NULL, role VARCHAR(20) NOT NULL, active BOOLEAN NOT NULL, archived BOOLEAN NOT NULL, hired_on VARCHAR(10) DEFAULT NULL, left_on VARCHAR(10) DEFAULT NULL, created_at VARCHAR(25) NOT NULL, department_id INTEGER DEFAULT NULL, schedule_id INTEGER DEFAULT NULL, CONSTRAINT FK_5D9F75A1AE80F5DF FOREIGN KEY (department_id) REFERENCES department (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_5D9F75A1A40BC2D5 FOREIGN KEY (schedule_id) REFERENCES work_schedule (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5D9F75A1E7927C74 ON employee (email)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5D9F75A177153098 ON employee (code)');
        $this->addSql('CREATE INDEX IDX_5D9F75A1AE80F5DF ON employee (department_id)');
        $this->addSql('CREATE INDEX IDX_5D9F75A1A40BC2D5 ON employee (schedule_id)');
        $this->addSql('CREATE TABLE holiday (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, day VARCHAR(10) NOT NULL, name VARCHAR(100) NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_DC9AB234E5A02990 ON holiday (day)');
        $this->addSql('CREATE TABLE leave_request (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, start_on VARCHAR(10) NOT NULL, end_on VARCHAR(10) NOT NULL, start_slot INTEGER NOT NULL, end_slot INTEGER NOT NULL, units INTEGER NOT NULL, status VARCHAR(20) NOT NULL, comment CLOB NOT NULL, decision_note CLOB NOT NULL, created_at VARCHAR(25) NOT NULL, decided_at VARCHAR(25) DEFAULT NULL, attachment VARCHAR(70) DEFAULT NULL, attachment_name VARCHAR(255) DEFAULT NULL, employee_id INTEGER NOT NULL, leave_type_id INTEGER NOT NULL, counter_type_id INTEGER DEFAULT NULL, decided_by_id INTEGER DEFAULT NULL, CONSTRAINT FK_7DC8F7788C03F15C FOREIGN KEY (employee_id) REFERENCES employee (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_7DC8F7788313F474 FOREIGN KEY (leave_type_id) REFERENCES leave_type (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_7DC8F7784B27D0B2 FOREIGN KEY (counter_type_id) REFERENCES counter_type (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_7DC8F778E26B496B FOREIGN KEY (decided_by_id) REFERENCES employee (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX leave_employee_period ON leave_request (employee_id, status, start_on, end_on)');
        $this->addSql('CREATE INDEX leave_status_period ON leave_request (status, start_on, end_on)');
        $this->addSql('CREATE INDEX IDX_7DC8F7788C03F15C ON leave_request (employee_id)');
        $this->addSql('CREATE INDEX IDX_7DC8F7788313F474 ON leave_request (leave_type_id)');
        $this->addSql('CREATE INDEX IDX_7DC8F7784B27D0B2 ON leave_request (counter_type_id)');
        $this->addSql('CREATE INDEX IDX_7DC8F778E26B496B ON leave_request (decided_by_id)');
        $this->addSql('CREATE TABLE leave_type (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(100) NOT NULL, tone VARCHAR(16) NOT NULL, icon VARCHAR(40) NOT NULL, description VARCHAR(255) NOT NULL, active BOOLEAN NOT NULL, requires_approval BOOLEAN NOT NULL, requires_attachment BOOLEAN NOT NULL, allow_half_days BOOLEAN NOT NULL, employee_visible BOOLEAN NOT NULL, private_reason BOOLEAN NOT NULL, counter_type_id INTEGER DEFAULT NULL, CONSTRAINT FK_E2BC43914B27D0B2 FOREIGN KEY (counter_type_id) REFERENCES counter_type (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E2BC43915E237E06 ON leave_type (name)');
        $this->addSql('CREATE INDEX IDX_E2BC43914B27D0B2 ON leave_type (counter_type_id)');
        $this->addSql('CREATE TABLE notification (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, message VARCHAR(220) NOT NULL, created_at VARCHAR(25) NOT NULL, read_at VARCHAR(25) DEFAULT NULL, employee_id INTEGER NOT NULL, CONSTRAINT FK_BF5476CA8C03F15C FOREIGN KEY (employee_id) REFERENCES employee (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_BF5476CA8C03F15C ON notification (employee_id)');
        $this->addSql('CREATE TABLE work_schedule (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(100) NOT NULL, days INTEGER NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8F8D9BA75E237E06 ON work_schedule (name)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE audit_entry');
        $this->addSql('DROP TABLE balance');
        $this->addSql('DROP TABLE balance_movement');
        $this->addSql('DROP TABLE counter_type');
        $this->addSql('DROP TABLE department');
        $this->addSql('DROP TABLE employee');
        $this->addSql('DROP TABLE holiday');
        $this->addSql('DROP TABLE leave_request');
        $this->addSql('DROP TABLE leave_type');
        $this->addSql('DROP TABLE notification');
        $this->addSql('DROP TABLE work_schedule');
    }
}
