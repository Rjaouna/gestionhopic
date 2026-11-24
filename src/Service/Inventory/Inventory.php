<?php

namespace App\Service\Inventory;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use DomainException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class Inventory
{
    private const int PAGE_SIZE = 20;
    private const int REOPEN_SECONDS = 300;
    private const string ASSIGNMENTS_FROM = ' FROM inventory_assignment a JOIN inventory_campaign c ON c.id = a.campaign_id
        JOIN employee e ON e.id = a.employee_id';
    private const string EMPLOYEE_VISIBLE = 'a.employee_id = ? AND c.closed_at IS NULL
        AND (a.closed_at IS NULL OR (a.review_skipped_at IS NULL AND a.closed_at > ?))';

    public function __construct(private Connection $db, private EbpCsv $csv, private EbpCatalog $catalog)
    {
    }

    public function active(): array|false
    {
        return $this->db->fetchAssociative('SELECT * FROM inventory_campaign WHERE closed_at IS NULL ORDER BY id DESC LIMIT 1');
    }

    public function campaign(int $id): array
    {
        return $this->db->fetchAssociative('SELECT * FROM inventory_campaign WHERE id = ?', [$id])
            ?: throw new NotFoundHttpException('Inventaire introuvable.');
    }

    public function scopeOptions(): array
    {
        $campaign = $this->active();
        $source = $campaign ? 'inventory_line WHERE campaign_id = ?' : 'ebp_article WHERE present = 1';
        $parameters = $campaign ? [$campaign['id']] : [];
        $completed = $campaign ? 'count(*) = sum(completed)' : '0';

        return [
            'campaign' => $campaign,
            'brands' => $this->db->fetchAllAssociative('SELECT brand AS value, '.$completed.' AS inventoried FROM '.$source
                .' GROUP BY brand ORDER BY brand COLLATE NOCASE', $parameters),
            'categories' => $this->db->fetchAllAssociative('SELECT category AS value, '.$completed.' AS inventoried FROM '.$source
                .' GROUP BY category ORDER BY category COLLATE NOCASE', $parameters),
        ];
    }

    public function preview(string $brandValue, string $categoryValue): array
    {
        $campaign = $this->active();
        [$where, $values] = $this->scope($this->decodeScope($brandValue), $this->decodeScope($categoryValue));
        $source = $campaign ? 'inventory_line WHERE campaign_id = ?' : 'ebp_article WHERE present = 1';
        $parameters = $campaign ? [$campaign['id'], ...$values] : $values;
        $columns = $campaign ? 'count(assignment_id) AS assigned, count(CASE WHEN completed = 1 THEN 1 END) AS finished' : '0 AS assigned, 0 AS finished';
        $counts = $this->db->fetchAssociative('SELECT count(*) AS total, '.$columns.' FROM '.$source.$where, $parameters);

        return [
            'campaign' => (int) ($campaign['id'] ?? 0),
            'total' => (int) $counts['total'],
            'assigned' => (int) $counts['assigned'],
            'inventoried' => (int) $counts['total'] > 0 && (int) $counts['total'] === (int) $counts['finished'],
            'available' => (int) $counts['total'] - (int) $counts['assigned'],
        ];
    }

    public function assign(int $employee, string $brandValue, string $categoryValue, string $name, int $campaignId, int $available): int
    {
        $brand = $this->decodeScope($brandValue);
        $category = $this->decodeScope($categoryValue);
        $name = trim($name);
        if (mb_strlen($name) > 120 || $available <= 0) {
            throw new DomainException('Choisissez un périmètre contenant des articles disponibles.');
        }

        return $this->db->transactional(function () use ($employee, $brand, $category, $name, $campaignId, $available): int {
            $this->writeLock();
            if (!$this->db->fetchOne('SELECT 1 FROM employee WHERE id = ? AND active = 1 AND archived = 0', [$employee])) {
                throw new DomainException('Sélectionnez un collaborateur actif.');
            }
            $campaign = $this->active();
            if ((int) ($campaign['id'] ?? 0) !== $campaignId) {
                throw new DomainException('L’inventaire actif a changé. Rouvrez cette fenêtre pour actualiser le périmètre.');
            }
            if (!$campaign) {
                $name = $name ?: 'Inventaire du '.date('d/m/Y');
                $this->db->insert('inventory_campaign', ['name' => $name, 'started_at' => time()]);
                $campaignId = (int) $this->db->lastInsertId();
                $this->db->executeStatement('INSERT INTO inventory_line
                    (campaign_id, unique_id, reference, description, brand, category, web_code, search_text, expected_quantity, completed, version)
                    SELECT ?, unique_id, reference, description, brand, category, web_code, search_text, stock, 0, 0
                    FROM ebp_article WHERE present = 1', [$campaignId]);
            } else {
                $name = $campaign['name'];
            }
            [$where, $values] = $this->scope($brand, $category);
            $this->db->insert('inventory_assignment', [
                'campaign_id' => $campaignId, 'employee_id' => $employee, 'brand' => $brand,
                'category' => $category, 'assigned_at' => time(),
            ]);
            $assignmentId = (int) $this->db->lastInsertId();
            $affected = $this->db->executeStatement('UPDATE inventory_line SET assignment_id = ?
                WHERE campaign_id = ? AND assignment_id IS NULL'.$where, [$assignmentId, $campaignId, ...$values]);
            if ($affected !== $available) {
                throw new DomainException('Le nombre d’articles a changé. Recalculez le périmètre avant de valider.');
            }
            $this->db->insert('notification', [
                'employee_id' => $employee, 'message' => $name.' · '.$affected.' articles à inventorier.',
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return $assignmentId;
        });
    }

    public function adminView(int $id, int $assignment, string $search, string $state, int $page): array
    {
        $campaigns = $this->db->fetchAllAssociative('SELECT id, name, started_at, closed_at FROM inventory_campaign ORDER BY id DESC');
        $campaign = array_find($campaigns, static fn (array $row): bool => (int) $row['id'] === $id) ?? ($campaigns[0] ?? null);
        $assignments = $campaign ? $this->assignments('a.campaign_id = ?', [$campaign['id']]) : [];
        $reviewedAssignment = array_find($assignments, static fn (array $row): bool => (int) $row['id'] === $assignment);

        return [
            'campaigns' => $campaigns, 'campaign' => $campaign, 'lineState' => $state,
            'inventoryStats' => $campaign ? $this->stats((int) $campaign['id']) : null,
            'assignments' => $assignments, 'reviewedAssignment' => $reviewedAssignment,
        ] + ($campaign ? $this->lines((int) $campaign['id'], $reviewedAssignment ? (int) $reviewedAssignment['id'] : null,
            $search, $state, $page, comparison: true) : []);
    }

    public function assignmentList(?int $employee, string $search, int $page): array
    {
        $where = $employee === null ? '1 = 1' : self::EMPLOYEE_VISIBLE;
        $now = time();
        $values = $employee === null ? [] : [$employee, $now - self::REOPEN_SECONDS];
        if ($search !== '') {
            $where .= " AND instr(lower(c.name || ' ' || e.first_name || ' ' || e.last_name || ' ' || e.code || ' '
                || coalesce(a.brand, '') || ' ' || coalesce(a.category, '')), lower(?)) > 0";
            $values[] = mb_substr($search, 0, 160);
        }
        $count = (int) $this->db->fetchOne('SELECT count(*)'.self::ASSIGNMENTS_FROM.' WHERE '.$where, $values);
        $pagination = $this->pagination($count, $page);
        $jobs = $this->assignments($where, $values, $pagination);

        return ['assignment' => null, 'assignments' => array_map(fn (array $job) => $job + [
            'reopen_seconds' => $job['review_skipped_at'] === null ? $this->reopenSeconds((int) $job['closed_at'], $now) : 0,
        ], $jobs), 'pagination' => $pagination];
    }

    public function assignmentView(int $assignment, ?int $employee, string $search, int $page): array
    {
        $job = $this->assignment($assignment, $employee);
        $job += $this->db->fetchAssociative('SELECT count(DISTINCT category) AS category_count, min(category) AS sole_category
            FROM inventory_line WHERE assignment_id = ?', [$assignment]);

        return ['assignment' => $job, 'assignmentStats' => $this->assignmentStats($assignment)]
            + $this->lines((int) $job['campaign_id'], $assignment, $search, '', $page, comparison: false);
    }

    public function employeeSummary(int $employee): array
    {
        $now = time();
        $summary = $this->db->fetchAssociative('SELECT count(DISTINCT a.id) AS assignments,
            count(DISTINCT CASE WHEN a.closed_at IS NULL THEN a.id END) AS open_assignments,
            count(CASE WHEN a.closed_at IS NULL AND l.completed = 0 THEN 1 END) AS remaining,
            min(a.closed_at) AS next_close'.self::ASSIGNMENTS_FROM.' JOIN inventory_line l ON l.assignment_id = a.id
            WHERE '.self::EMPLOYEE_VISIBLE, [$employee, $now - self::REOPEN_SECONDS]);

        $summary['reopen_seconds'] = $this->reopenSeconds((int) $summary['next_close'], $now);
        unset($summary['next_close']);

        return array_map('intval', $summary);
    }

    public function assignment(int $assignment, ?int $employee): array
    {
        $now = time();
        $where = $employee === null ? '' : ' AND '.self::EMPLOYEE_VISIBLE;
        $values = $employee === null ? [$assignment] : [$assignment, $employee, $now - self::REOPEN_SECONDS];
        $job = $this->db->fetchAssociative('SELECT a.*, c.name, c.closed_at AS campaign_closed, e.first_name, e.last_name'
            .self::ASSIGNMENTS_FROM.' WHERE a.id = ?'.$where, $values)
            ?: throw new NotFoundHttpException('Affectation introuvable.');

        return $job + ['reopen_seconds' => $job['review_skipped_at'] === null ? $this->reopenSeconds((int) $job['closed_at'], $now) : 0];
    }

    public function assignedArticle(int $id, ?int $employee): array
    {
        $row = $this->db->fetchAssociative('SELECT l.assignment_id, l.unique_id, l.reference, l.description, l.brand, l.category, l.web_code, c.photo_path
            FROM inventory_line l LEFT JOIN ebp_connection c ON c.id = 1
            WHERE l.id = ? AND l.assignment_id IS NOT NULL', [$id])
            ?: throw new NotFoundHttpException('Article introuvable dans les affectations.');
        $this->assignment((int) $row['assignment_id'], $employee);

        return $row + ['photo' => $this->catalog->photo($row, (string) $row['photo_path'])];
    }

    public function saveCount(?int $employee, int $assignment, int $line, string $quantity, bool $completed, int $version): array
    {
        $quantity = str_replace(',', '.', trim($quantity));
        if (!preg_match('/^\d{1,12}(?:\.\d{1,6})?$/D', $quantity)) {
            throw new DomainException('Saisissez une quantité positive ou nulle, avec au maximum six décimales.');
        }
        [$whole, $fraction] = explode('.', $quantity, 2) + [1 => ''];
        $whole = ltrim($whole, '0') ?: '0';
        $fraction = rtrim($fraction, '0');
        $quantity = $whole.($fraction !== '' ? '.'.$fraction : '');

        return $this->db->transactional(function () use ($employee, $assignment, $line, $quantity, $completed, $version): array {
            $this->writeLock();
            $job = $this->assignment($assignment, $employee);
            if ($job['closed_at'] || $job['campaign_closed']) {
                throw new ConflictHttpException('Cet inventaire est clôturé.');
            }
            $row = $this->db->fetchAssociative('SELECT version FROM inventory_line WHERE id = ? AND assignment_id = ?', [$line, $assignment]);
            if (!$row) {
                throw new NotFoundHttpException('Article introuvable dans cette affectation.');
            }
            if ((int) $row['version'] !== $version) {
                throw new ConflictHttpException('Cet article a été modifié dans une autre fenêtre. Actualisez la page.');
            }
            $this->db->update('inventory_line', [
                'counted_quantity' => $quantity, 'completed' => (int) $completed, 'version' => $version + 1,
            ], ['id' => $line]);

            return ['quantity' => $quantity, 'completed' => $completed, 'version' => $version + 1]
                + $this->assignmentStats($assignment);
        });
    }

    public function closeAssignment(int $assignment, ?int $employee): bool
    {
        return $this->db->transactional(function () use ($assignment, $employee): bool {
            $this->writeLock();
            $job = $this->assignment($assignment, $employee);
            if ($job['closed_at']) {
                return false;
            }
            if ($job['campaign_closed'] || $this->assignmentStats($assignment)['remaining'] > 0) {
                throw new DomainException('Terminez tous les articles de cette affectation avant de la clôturer.');
            }
            $this->db->update('inventory_assignment', ['closed_at' => time()], ['id' => $assignment]);

            return true;
        });
    }

    public function reopenAssignment(int $assignment, ?int $employee): bool
    {
        return $this->db->transactional(function () use ($assignment, $employee): bool {
            $this->writeLock();
            $job = $this->assignment($assignment, $employee);
            if ($job['campaign_closed']) {
                throw new DomainException('L’administration a déjà clôturé cet inventaire.');
            }
            if (!$job['closed_at']) {
                return false;
            }
            $this->db->update('inventory_assignment', ['closed_at' => null, 'review_skipped_at' => null], ['id' => $assignment]);
            $this->db->executeStatement('UPDATE inventory_line SET version = version + 1 WHERE assignment_id = ?', [$assignment]);

            return true;
        });
    }

    public function zeroPreview(int $assignment, ?int $employee): array
    {
        $job = $this->assignment($assignment, $employee);
        if ($job['closed_at'] || $job['campaign_closed']) {
            throw new DomainException('Cet inventaire est clôturé.');
        }

        return array_map('intval', $this->db->fetchAssociative('SELECT
            count(CASE WHEN completed = 0 THEN 1 END) AS remaining,
            count(CASE WHEN completed = 0 AND CAST(counted_quantity AS NUMERIC) <> 0 THEN 1 END) AS drafts,
            coalesce(sum(version), 0) AS revision
            FROM inventory_line WHERE assignment_id = ?', [$assignment]));
    }

    public function zeroRemaining(int $assignment, ?int $employee, int $revision): int
    {
        return $this->db->transactional(function () use ($assignment, $employee, $revision): int {
            $this->writeLock();
            $preview = $this->zeroPreview($assignment, $employee);
            if ($preview['remaining'] === 0) {
                return 0;
            }
            if ($revision !== $preview['revision']) {
                throw new DomainException('Le comptage a changé. Rouvrez cette fenêtre pour vérifier les quantités à remplacer.');
            }

            return $this->db->executeStatement("UPDATE inventory_line SET counted_quantity = '0', completed = 1, version = version + 1
                WHERE assignment_id = ? AND completed = 0", [$assignment]);
        });
    }

    public function closeCampaign(int $campaign): bool
    {
        return $this->db->transactional(function () use ($campaign): bool {
            $this->writeLock();
            if ($this->campaign($campaign)['closed_at']) {
                return false;
            }
            $progress = $this->stats($campaign);
            if (!$progress['readyToClose']) {
                throw new DomainException('Terminez le comptage de tous les articles et clôturez toutes les affectations.');
            }
            if ($progress['reviewUntil']) {
                throw new DomainException('La clôture finale sera disponible à la fin du délai de correction de cinq minutes.');
            }
            $this->db->update('inventory_campaign', ['closed_at' => time()], ['id' => $campaign]);

            return true;
        });
    }

    public function skipReviewDelay(int $campaign, int $expectedDeadline): bool
    {
        return $this->db->transactional(function () use ($campaign, $expectedDeadline): bool {
            $this->writeLock();
            if ($this->campaign($campaign)['closed_at']) {
                return false;
            }
            $progress = $this->stats($campaign);
            if (!$progress['readyToClose']) {
                throw new DomainException('Terminez le comptage et clôturez les affectations avant d’annuler le délai.');
            }
            if ($progress['reviewUntil'] === null) {
                return false;
            }
            if ($progress['reviewUntil'] !== $expectedDeadline) {
                throw new DomainException('Le délai a changé. Rouvrez la confirmation pour vérifier le délai actuel.');
            }
            $now = time();

            return $this->db->executeStatement('UPDATE inventory_assignment SET review_skipped_at = ?
                WHERE campaign_id = ? AND review_skipped_at IS NULL AND closed_at > ?', [$now, $campaign, $now - self::REOPEN_SECONDS]) > 0;
        });
    }

    public function cancellableAssignment(int $assignment): array
    {
        $job = $this->assignment($assignment, null);
        if ($job['campaign_closed']) {
            throw new DomainException('Cet inventaire est clôturé.');
        }

        return $job + $this->assignmentStats($assignment);
    }

    public function cancelAssignment(int $assignment): void
    {
        $this->db->transactional(function () use ($assignment): void {
            $this->writeLock();
            $this->cancellableAssignment($assignment);
            $this->db->executeStatement('UPDATE inventory_line SET assignment_id = NULL, counted_quantity = NULL, completed = 0, version = version + 1
                WHERE assignment_id = ?', [$assignment]);
            $this->db->delete('inventory_assignment', ['id' => $assignment]);
        });
    }

    public function cancelCampaign(int $campaign): void
    {
        $this->db->transactional(function () use ($campaign): void {
            $this->writeLock();
            if ($this->campaign($campaign)['closed_at']) {
                throw new DomainException('Cet inventaire est clôturé.');
            }
            $this->db->delete('inventory_line', ['campaign_id' => $campaign]);
            $this->db->delete('inventory_assignment', ['campaign_id' => $campaign]);
            $this->db->delete('inventory_campaign', ['id' => $campaign]);
        });
    }

    public function stats(int $campaign): array
    {
        $counts = $this->db->fetchAssociative('SELECT count(*) AS total, count(assignment_id) AS assigned,
            count(CASE WHEN completed = 1 THEN 1 END) AS finished,
            count(CASE WHEN completed = 0 AND counted_quantity IS NOT NULL THEN 1 END) AS counting,
            count(CASE WHEN completed = 1 AND '.self::differenceSql().' THEN 1 END) AS discrepancies
            FROM inventory_line WHERE campaign_id = ?', [$campaign]);
        $counts = array_map('intval', $counts);
        $status = $this->db->fetchAssociative('SELECT count(CASE WHEN closed_at IS NULL THEN 1 END) AS open,
            max(CASE WHEN review_skipped_at IS NULL THEN closed_at END) AS last_close FROM inventory_assignment WHERE campaign_id = ?', [$campaign]);
        $open = (int) $status['open'];
        $now = time();
        $reviewSeconds = $this->reopenSeconds((int) $status['last_close'], $now);
        $readyToClose = $counts['total'] > 0 && $counts['finished'] === $counts['total'] && $open === 0;

        return $counts + [
            'remaining' => $counts['total'] - $counts['finished'], 'unassigned' => $counts['total'] - $counts['assigned'],
            'openAssignments' => $open, 'reviewUntil' => $reviewSeconds ? $now + $reviewSeconds : null,
            'readyToClose' => $readyToClose, 'canClose' => $readyToClose && !$reviewSeconds,
        ];
    }

    public function exportRows(int $campaign): iterable
    {
        return $this->db->iterateAssociative('SELECT unique_id, counted_quantity FROM inventory_line
            WHERE campaign_id = ? AND completed = 1 ORDER BY reference COLLATE NOCASE, id', [$campaign]);
    }

    private function assignmentStats(int $assignment): array
    {
        $stats = $this->db->fetchAssociative('SELECT count(*) AS total, count(CASE WHEN completed = 1 THEN 1 END) AS finished
            FROM inventory_line WHERE assignment_id = ?', [$assignment]);

        return ['total' => (int) $stats['total'], 'finished' => (int) $stats['finished'],
            'remaining' => (int) $stats['total'] - (int) $stats['finished']];
    }

    private function assignments(string $where, array $values, ?array $pagination = null): array
    {
        $limit = $pagination ? ' LIMIT '.self::PAGE_SIZE.' OFFSET '.$pagination['offset'] : '';

        return $this->db->fetchAllAssociative('SELECT a.*, c.name, c.closed_at AS campaign_closed, e.first_name, e.last_name,
            count(l.id) AS total, count(CASE WHEN l.completed = 1 THEN 1 END) AS finished,
            count(CASE WHEN l.completed = 0 AND l.counted_quantity IS NOT NULL THEN 1 END) AS counting,
            count(DISTINCT l.category) AS category_count, min(l.category) AS sole_category'
            .self::ASSIGNMENTS_FROM.' JOIN inventory_line l ON l.assignment_id = a.id
            WHERE '.$where.' GROUP BY a.id ORDER BY c.closed_at IS NOT NULL, a.closed_at IS NOT NULL, a.id DESC'.$limit, $values);
    }

    private function lines(int $campaign, ?int $assignment, string $search, string $state, int $page, bool $comparison): array
    {
        $values = [$campaign];
        $where = ['campaign_id = ?'];
        $columns = 'id, unique_id, reference, description, brand, category, web_code, counted_quantity, completed, version';
        if ($assignment !== null) {
            $where[] = 'assignment_id = ?';
            $values[] = $assignment;
        } else {
            $where[] = 'assignment_id IS NOT NULL';
        }
        if ($comparison) {
            $columns .= ', expected_quantity, CAST(counted_quantity AS NUMERIC) - CAST(expected_quantity AS NUMERIC) AS difference';
            $status = match ($state) {
                'a-compter' => 'completed = 0',
                'ecarts' => 'completed = 1 AND '.self::differenceSql(),
                default => null,
            };
            if ($status !== null) {
                $where[] = $status;
            }
        }
        foreach (preg_split('/\s+/u', $this->csv->searchText(mb_substr($search, 0, 160)), flags: PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $where[] = 'instr(search_text, ?) > 0';
            $values[] = $word;
        }
        $from = ' FROM inventory_line WHERE '.implode(' AND ', $where);
        $total = (int) $this->db->fetchOne('SELECT count(*)'.$from, $values);
        $pagination = $this->pagination($total, $page);
        $sort = $comparison && $assignment !== null ? 'completed DESC, counted_quantity IS NOT NULL DESC, ' : '';
        $rows = $this->db->fetchAllAssociative('SELECT '.$columns.$from.' ORDER BY '.$sort.'reference COLLATE NOCASE, id LIMIT ? OFFSET ?',
            [...$values, self::PAGE_SIZE, $pagination['offset']], [...array_fill(0, count($values), ParameterType::STRING), ParameterType::INTEGER, ParameterType::INTEGER]);
        if (!$comparison) {
            $path = (string) $this->db->fetchOne('SELECT photo_path FROM ebp_connection WHERE id = 1');
            $rows = array_map(fn (array $row) => $row + ['photo' => $this->catalog->photo($row, $path)], $rows);
        }

        return ['inventoryLines' => $rows, 'pagination' => $pagination];
    }

    private function pagination(int $total, int $page): array
    {
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = max(1, min($pages, $page));

        return ['parameter' => 'page', 'page' => $page, 'pages' => $pages, 'total' => $total,
            'offset' => ($page - 1) * self::PAGE_SIZE, 'size' => self::PAGE_SIZE];
    }

    private function reopenSeconds(int $closedAt, int $now): int
    {
        return $closedAt ? max(0, $closedAt + self::REOPEN_SECONDS - $now) : 0;
    }

    private function writeLock(): void
    {
        $this->db->executeStatement('UPDATE inventory_campaign SET id = id WHERE 0');
    }

    private function decodeScope(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        if (!str_starts_with($value, '=') || mb_strlen($value) > 256) {
            throw new DomainException('Le périmètre sélectionné est invalide.');
        }

        return substr($value, 1);
    }

    private function scope(?string $brand, ?string $category): array
    {
        $where = '';
        $values = [];
        foreach (['brand' => $brand, 'category' => $category] as $column => $value) {
            if ($value !== null) {
                $where .= ' AND '.$column.' = ?';
                $values[] = $value;
            }
        }

        return [$where, $values];
    }

    private static function differenceSql(): string
    {
        return 'CAST(counted_quantity AS NUMERIC) <> CAST(expected_quantity AS NUMERIC)';
    }
}
