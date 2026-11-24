<?php

namespace App\Service\Inventory;

use Doctrine\DBAL\Connection;
use DomainException;

final readonly class EbpSynchronization
{
    public function __construct(
        private Connection $db,
        private EbpSource $source,
        private EbpCsv $csv,
        private EbpCatalog $catalog,
    ) {
    }

    public function status(): array
    {
        $row = $this->connection();

        return [
            'state' => $row['state'], 'attempts' => (int) $row['attempts'],
            'nextAttemptAt' => (int) $row['next_attempt_at'], 'leasedUntil' => (int) $row['leased_until'],
            'message' => $row['message'], 'lastImportAt' => $row['last_import_at'], 'lastCount' => (int) $row['last_count'],
            'sourceUrl' => $row['source_url'], 'photoPath' => $row['photo_path'],
        ];
    }

    public function configure(string $url, string $photoPath): array
    {
        $url = $this->source->httpsUrl($url);
        $photoPath = trim($photoPath);
        if ($photoPath !== '') {
            $sample = str_replace(['{reference}', '{code_web}', '{unique_id}'], 'article', $photoPath);
            if (str_contains($sample, '{') || str_contains($sample, '}')) {
                throw new DomainException('Utilisez les variables {reference}, {code_web} ou {unique_id} dans le chemin des photos.');
            }
            $this->source->httpsUrl($sample);
        }
        $this->db->transactional(function () use ($url, $photoPath): void {
            $this->db->executeStatement('UPDATE ebp_connection SET id = id WHERE id = 1');
            $current = $this->connection();
            $changes = ['source_url' => $url, 'photo_path' => $photoPath];
            if ($url !== $current['source_url']) {
                $changes += $this->idleState();
            }
            $this->db->update('ebp_connection', $changes, ['id' => 1]);
        });

        return $this->status();
    }

    public function test(string $url): array
    {
        return $this->download($url, function ($file): array {
            $count = 0;
            $categories = $brands = [];
            foreach ($this->csv->rows($file) as $row) {
                ++$count;
                if ($row['category'] !== '') {
                    $categories[$row['category']] = true;
                }
                if ($row['brand'] !== '') {
                    $brands[$row['brand']] = true;
                }
            }

            return ['count' => $count, 'categories' => count($categories), 'brands' => count($brands)];
        });
    }

    public function enqueue(): array
    {
        $this->db->executeStatement("UPDATE ebp_connection SET state = 'queued', attempts = 0, next_attempt_at = 0,
            message = '', lease = NULL, leased_until = 0 WHERE id = 1 AND state IN ('idle', 'failed', 'success')");

        return $this->status();
    }

    public function clearCatalogue(): int
    {
        return $this->db->transactional(function (): int {
            // Invalidate any pending or running import before removing its destination.
            $this->db->update('ebp_connection', ['last_import_at' => null, 'last_count' => 0] + $this->idleState(), ['id' => 1]);

            return $this->db->executeStatement('DELETE FROM ebp_article');
        });
    }

    public function run(): array
    {
        $lease = bin2hex(random_bytes(16));
        $now = time();
        $claimed = $this->db->executeStatement("UPDATE ebp_connection SET state = 'running', attempts = attempts + 1,
            lease = ?, leased_until = ?, message = ''
            WHERE id = 1 AND (state = 'queued' OR (state = 'waiting' AND next_attempt_at <= ?)
            OR (state = 'running' AND leased_until <= ?))", [$lease, $now + 120, $now, $now]);
        if (!$claimed) {
            return $this->status();
        }
        $job = $this->connection();
        if ($job['lease'] !== $lease) {
            return $this->status();
        }
        try {
            $rows = $this->download($job['source_url'], fn ($file) => iterator_to_array($this->csv->rows($file), false));
            $this->apply($rows, $lease);
        } catch (EbpUnavailable $error) {
            $delay = min(300, 5 * (2 ** min(6, max(0, (int) $job['attempts'] - 1))));
            $this->finish($lease, 'waiting', $error->getMessage(), time() + $delay);
        } catch (DomainException $error) {
            $this->finish($lease, 'failed', $error->getMessage());
        } catch (\Doctrine\DBAL\Exception\RetryableException) {
            $this->finish($lease, 'waiting', 'Le catalogue est occupé. Une nouvelle tentative est programmée.', time() + 5);
        } catch (\Throwable $error) {
            $this->finish($lease, 'failed', 'La synchronisation a été interrompue. Le catalogue précédent est conservé.');
            throw $error;
        }

        return $this->status();
    }

    public function importFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path) || filesize($path) > EbpSource::MAX_BYTES) {
            throw new DomainException('Le fichier CSV est introuvable ou dépasse 20 Mo.');
        }
        $file = fopen($path, 'rb');
        if ($file === false) {
            throw new DomainException('Le fichier CSV ne peut pas être lu.');
        }
        try {
            $rows = iterator_to_array($this->csv->rows($file), false);
        } finally {
            fclose($file);
        }
        $this->apply($rows, null);

        return $this->status();
    }

    private function connection(): array
    {
        return $this->db->fetchAssociative('SELECT * FROM ebp_connection WHERE id = 1')
            ?: throw new \LogicException('La source EBP doit être initialisée par la migration.');
    }

    private function download(string $url, callable $read): array
    {
        $file = $this->source->download($url);
        try {
            return $read($file);
        } finally {
            fclose($file);
        }
    }

    private function apply(array $rows, ?string $lease): void
    {
        $this->db->transactional(function () use ($rows, $lease): void {
            $this->db->executeStatement('UPDATE ebp_connection SET id = id WHERE id = 1');
            $connection = $this->connection();
            if ($lease !== null && $connection['lease'] !== $lease) {
                return;
            }
            if ($lease === null && in_array($connection['state'], ['queued', 'running', 'waiting'], true)) {
                throw new DomainException('Une synchronisation du catalogue est déjà en cours.');
            }
            $this->catalog->replace($rows);
            $this->db->update('ebp_connection', [
                'state' => 'success', 'last_import_at' => time(), 'last_count' => count($rows),
            ] + $this->idleState(), ['id' => 1]);
        });
    }

    private function finish(string $lease, string $state, string $message, int $nextAttempt = 0): void
    {
        $this->db->update('ebp_connection', [
            'state' => $state, 'message' => mb_substr($message, 0, 255), 'next_attempt_at' => $nextAttempt,
            'lease' => null, 'leased_until' => 0,
        ], ['id' => 1, 'lease' => $lease]);
    }

    private function idleState(): array
    {
        return ['state' => 'idle', 'attempts' => 0, 'next_attempt_at' => 0, 'lease' => null, 'leased_until' => 0, 'message' => ''];
    }
}
