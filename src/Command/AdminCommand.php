<?php

namespace App\Command;

use App\Entity\Employee;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'tempo:admin', description: 'Créer un compte administrateur')]
final readonly class AdminCommand
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
        #[Autowire('%kernel.project_dir%/config/fixtures/production.php')] private string $fixturesPath,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Charger la fixture de production et créer le premier administrateur')] bool $initialiser = false,
    ): int {
        $db = $this->em->getConnection();
        if ($initialiser && $db->fetchOne('SELECT 1 FROM employee LIMIT 1')) {
            $io->error('L’initialisation est réservée à une base sans compte utilisateur.');

            return Command::INVALID;
        }

        $fixtures = $initialiser ? require $this->fixturesPath : null;
        $account = $fixtures['admin'] ?? [
            'email' => (string) $io->ask('Adresse e-mail'),
            'firstName' => (string) $io->ask('Prénom'),
            'lastName' => (string) $io->ask('Nom'),
        ];
        $email = mb_strtolower(trim($account['email']));
        $first = trim($account['firstName']);
        $last = trim($account['lastName']);
        $password = getenv('TEMPO_ADMIN_PASSWORD');
        if ($password === false) {
            $question = (new Question('Mot de passe (12 à 72 caractères)'))->setHidden(true)->setHiddenFallback(false);
            $password = (string) $io->askQuestion($question);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 180 || $first === '' || $last === ''
            || mb_strlen($first) > 100 || mb_strlen($last) > 100) {
            $io->error('Renseignez un prénom, un nom et une adresse e-mail valide.');

            return Command::INVALID;
        }
        if (mb_strlen($password) < 12 || mb_strlen($password) > 72) {
            $io->error('Renseignez un mot de passe de 12 à 72 caractères. En mode automatisé, injectez TEMPO_ADMIN_PASSWORD.');

            return Command::INVALID;
        }
        $employee = new Employee();
        $employee->firstName = $first;
        $employee->lastName = $last;
        $employee->email = $email;
        $employee->code = 'RH-'.strtoupper(bin2hex(random_bytes(5)));
        $employee->role = 'ROLE_ADMIN';
        $employee->createdAt = date('Y-m-d H:i:s');
        $employee->password = $this->hasher->hashPassword($employee, $password);
        try {
            $this->em->wrapInTransaction(function () use ($db, $fixtures, $employee): void {
                if ($fixtures !== null) {
                    // Prevent simultaneous initialization from two console processes.
                    $db->executeStatement('UPDATE work_schedule SET days = days');
                    if ($db->fetchOne('SELECT 1 FROM employee LIMIT 1')) {
                        throw new \DomainException('L’initialisation est réservée à une base sans compte utilisateur.');
                    }
                    $this->installFixtures($db, $fixtures);
                }
                $this->em->persist($employee);
            });
        } catch (UniqueConstraintViolationException) {
            $io->error('Une adresse e-mail ou un réglage de la fixture existe déjà.');

            return Command::INVALID;
        } catch (\DomainException $error) {
            $io->error($error->getMessage());

            return Command::INVALID;
        }

        $io->success($initialiser ? 'Réglages installés et compte administrateur créé.' : 'Compte administrateur créé.');

        return Command::SUCCESS;
    }

    private function installFixtures(Connection $db, array $fixtures): void
    {
        foreach ($fixtures['departments'] as $department) {
            $db->insert('department', $department + ['description' => '', 'active' => 1]);
        }
        $counters = [];
        foreach ($fixtures['counters'] as $counter) {
            $db->insert('counter_type', $counter + ['active' => 1]);
            $counters[$counter['name']] = (int) $db->lastInsertId();
        }
        $db->update('paid_leave_rule', ['counter_type_id' => $counters['Congés payés'] ?? null], ['id' => 1]);
        foreach ($fixtures['leaveTypes'] as $type) {
            $counter = $type['counter'] ?? null;
            unset($type['counter']);
            $db->insert('leave_type', $type + [
                'description' => '', 'active' => 1, 'requires_approval' => 1, 'requires_attachment' => 0,
                'allow_attachment' => 1, 'employee_visible' => 1, 'allow_half_days' => 1, 'private_reason' => 0,
                'counter_type_id' => $counter === null ? null
                    : ($counters[$counter] ?? throw new \DomainException('Compteur absent de la fixture : '.$counter)),
            ]);
        }
        foreach ($fixtures['holidays'] as $day => $name) {
            $db->executeStatement('INSERT INTO holiday (day, name) VALUES (?, ?)
                ON CONFLICT(day) DO UPDATE SET name = excluded.name', [$day, $name]);
        }
        $flags = ['Lundi' => 1, 'Mardi' => 2, 'Mercredi' => 4, 'Jeudi' => 8, 'Vendredi' => 16, 'Samedi' => 32, 'Dimanche' => 64];
        $days = 0;
        foreach ($fixtures['schedule']['days'] as $day) {
            $days |= $flags[$day] ?? throw new \DomainException('Jour de travail inconnu dans la fixture : '.$day);
        }
        if ($days === 0) {
            throw new \DomainException('La fixture doit prévoir au moins un jour de travail.');
        }
        $db->executeStatement('UPDATE work_schedule SET name = ?, days = ?', [$fixtures['schedule']['name'], $days]);
        $db->update('app_appearance', $fixtures['appearance'], ['id' => 1]);
    }
}
