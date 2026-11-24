<?php

namespace App\Service\Inventory;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;

final readonly class EbpCatalog
{
    private const int PAGE_SIZE = 20;

    public function __construct(private Connection $db, private EbpCsv $csv)
    {
    }

    public function browse(string $search, string $category, string $brand, int $page): array
    {
        $criteria = ['present = 1'];
        $inputs = [];
        foreach (['category' => $category, 'brand' => $brand] as $column => $value) {
            if ($value !== '') {
                $criteria[] = $column.' = ?';
                $inputs[] = $value;
            }
        }
        foreach (preg_split('/\s+/u', $this->csv->searchText(mb_substr($search, 0, 160)), flags: PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $criteria[] = 'instr(search_text, ?) > 0';
            $inputs[] = $word;
        }
        $where = ' FROM ebp_article WHERE '.implode(' AND ', $criteria);
        $total = (int) $this->db->fetchOne('SELECT count(*)'.$where, $inputs);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $current = max(1, min($pages, $page));
        $offset = ($current - 1) * self::PAGE_SIZE;
        $rows = $this->db->fetchAllAssociative('SELECT id, unique_id, reference, description, stock, counted_quantity, brand, category, web_code'
            .$where.' ORDER BY reference COLLATE NOCASE, id LIMIT ? OFFSET ?', [...$inputs, self::PAGE_SIZE, $offset],
            [...array_fill(0, count($inputs), ParameterType::STRING), ParameterType::INTEGER, ParameterType::INTEGER]);
        $rows = $this->withInventoryCounts($rows);
        $photoPath = (string) $this->db->fetchOne('SELECT photo_path FROM ebp_connection WHERE id = 1');
        $categories = $this->db->fetchFirstColumn("SELECT DISTINCT category FROM ebp_article WHERE present = 1 AND category <> '' ORDER BY category COLLATE NOCASE");
        $brands = $this->db->fetchFirstColumn("SELECT DISTINCT brand FROM ebp_article WHERE present = 1 AND brand <> '' ORDER BY brand COLLATE NOCASE");
        $stats = $this->db->fetchAssociative('SELECT count(*) AS articles, count(CASE WHEN CAST(stock AS REAL) > 0 THEN 1 END) AS available
            FROM ebp_article WHERE present = 1');

        return [
            'articles' => array_map(fn (array $article) => $article + ['photo' => $this->photo($article, $photoPath)], $rows),
            'articleStats' => $stats + ['categories' => count($categories), 'brands' => count($brands)],
            'articleCategories' => $categories, 'articleBrands' => $brands,
            'articleCategory' => $category, 'articleBrand' => $brand,
            'pagination' => [
                'parameter' => 'page', 'page' => $current, 'pages' => $pages,
                'total' => $total, 'offset' => $offset, 'size' => self::PAGE_SIZE,
            ],
        ];
    }

    public function article(int $id): array|false
    {
        $article = $this->db->fetchAssociative('SELECT * FROM ebp_article WHERE id = ?', [$id]);
        if (!$article) {
            return false;
        }
        [$article] = $this->withInventoryCounts([$article]);
        $path = (string) $this->db->fetchOne('SELECT photo_path FROM ebp_connection WHERE id = 1');

        return $article + [
            'photo' => $this->photo($article, $path),
            'fields' => json_decode($article['source_fields'], true, flags: JSON_THROW_ON_ERROR),
        ];
    }

    /** Installs a fully validated export inside the caller's transaction. */
    public function replace(array $rows): void
    {
        $this->db->executeStatement('UPDATE ebp_article SET present = 0 WHERE present = 1');
        $statement = $this->db->prepare('INSERT INTO ebp_article
            (unique_id, reference, description, stock, brand, category, web_code, search_text, source_fields, present)
            VALUES (:unique_id, :reference, :description, :stock, :brand, :category, :web_code, :search_text, :source_fields, 1)
            ON CONFLICT(unique_id) DO UPDATE SET reference = excluded.reference, description = excluded.description,
                stock = excluded.stock, brand = excluded.brand, category = excluded.category, web_code = excluded.web_code,
                search_text = excluded.search_text, source_fields = excluded.source_fields, present = 1');
        foreach ($rows as $row) {
            foreach ($row as $field => $value) {
                $statement->bindValue($field, $value);
            }
            $statement->executeStatement();
        }
    }

    public function photo(array $article, string $path): ?string
    {
        if ($path === '') {
            return null;
        }
        $keys = [
            '{reference}' => rawurlencode($article['reference']),
            '{code_web}' => rawurlencode($article['web_code'] ?: $article['reference']),
            '{unique_id}' => rawurlencode($article['unique_id']),
        ];
        $resolved = strtr($path, $keys);

        return $resolved !== $path ? $resolved : rtrim($path, '/').'/'.$keys['{code_web}'].'.jpg';
    }

    private function withInventoryCounts(array $articles): array
    {
        if (!$articles) {
            return [];
        }
        $counts = $this->db->fetchAllAssociativeIndexed("SELECT article.unique_id, coalesce(l.counted_quantity, '0') AS counted_quantity,
            (c.closed_at IS NULL) AS inventory_active
            FROM ebp_article article JOIN inventory_line l ON l.id = (
                SELECT candidate.id FROM inventory_campaign recent JOIN inventory_line candidate
                    ON candidate.campaign_id = recent.id AND candidate.unique_id = article.unique_id
                WHERE candidate.assignment_id IS NOT NULL ORDER BY recent.id DESC LIMIT 1
            ) JOIN inventory_campaign c ON c.id = l.campaign_id
            WHERE article.unique_id IN (?)",
            [array_column($articles, 'unique_id')], [ArrayParameterType::STRING]);

        return array_map(static fn (array $article) => array_replace($article, ['inventory_active' => false],
            $counts[$article['unique_id']] ?? []), $articles);
    }
}
