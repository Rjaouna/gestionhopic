<?php

namespace App\Service\Inventory;

use DomainException;
use Symfony\Component\String\UnicodeString;

final class EbpCsv
{
    private const array REQUIRED = ['Id', 'UniqueId', 'DesComClear', 'RealStock', 'FamilyName', 'xx_Marque'];

    /** @param resource $csv @return \Generator<int, array<string, string>> */
    public function rows($csv): \Generator
    {
        if (fread($csv, 3) !== "\xEF\xBB\xBF") {
            rewind($csv);
        }
        $columns = fgetcsv($csv, null, ';', '"', '');
        if (!$columns) {
            throw new DomainException('Le fichier CSV est vide.');
        }
        $columns = array_map(fn ($column) => trim(str_replace("\xEF\xBB\xBF", '', $this->utf8((string) $column))), $columns);
        if (array_diff(self::REQUIRED, $columns) || count($columns) !== count(array_unique($columns)) || in_array('', $columns, true)) {
            throw new DomainException('Colonnes EBP invalides. Vérifiez que le fichier est l’export CSV séparé par « ; ».');
        }
        $seen = [];
        $line = 1;
        while (($values = fgetcsv($csv, null, ';', '"', '')) !== false) {
            ++$line;
            if ($values === [null]) {
                continue;
            }
            if (count($values) !== count($columns)) {
                throw new DomainException('Ligne CSV '.$line.' incomplète ou mal formée.');
            }
            $fields = array_combine($columns, array_map(fn ($value) => $this->utf8((string) $value), $values));
            $unique = strtoupper(trim($fields['UniqueId']));
            $reference = trim($fields['Id']);
            $stock = str_replace(',', '.', trim($fields['RealStock']));
            if ($unique === '' || strlen($unique) > 100 || $reference === '' || strlen($stock) > 32
                || !preg_match('/^-?\d+(?:\.\d+)?$/D', $stock)) {
                throw new DomainException('Identifiant, référence ou quantité invalide à la ligne '.$line.'.');
            }
            if (isset($seen[$unique])) {
                throw new DomainException('Un identifiant EBP est en double à la ligne '.$line.'.');
            }
            $seen[$unique] = true;
            yield [
                'unique_id' => $unique,
                'reference' => $reference,
                'description' => trim($fields['DesComClear']),
                'stock' => $stock,
                'brand' => trim($fields['xx_Marque']),
                'category' => trim($fields['FamilyName']),
                'web_code' => trim($fields['xx_code_web'] ?? ''),
                'search_text' => $this->searchText(implode(' ', [
                    $reference, $fields['DesComClear'], $fields['xx_Marque'], $fields['FamilyName'],
                    $fields['xx_OEM'] ?? '', $fields['xx_correspondance'] ?? '', $fields['xx_code_web'] ?? '',
                    $fields['xx_Vehicule'] ?? '', $fields['xx_Annee'] ?? '', $unique,
                ])),
                'source_fields' => json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ];
        }
        if (!$seen) {
            throw new DomainException('Le CSV contient les en-têtes mais aucun article.');
        }
    }

    public function searchText(string $value): string
    {
        return (new UnicodeString($value))->ascii()->lower()->toString();
    }

    private function utf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }
}
