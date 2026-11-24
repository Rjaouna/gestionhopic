<?php

namespace App\Service;

use App\Entity\Employee;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\ParameterType;
use DomainException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class AppearanceSettings
{
    public function __construct(
        private Connection $db,
        private UrlGeneratorInterface $urls,
        private LeaveManager $audit,
    ) {
    }

    public function current(): array
    {
        try {
            $row = $this->db->fetchAssociative('SELECT name, suffix, theme, logo_version FROM app_appearance WHERE id = 1');
        } catch (TableNotFoundException) {
            $row = false;
        }
        $name = $row['name'] ?? 'tempo';
        $suffix = $row['suffix'] ?? 'RH';
        $theme = $row['theme'] ?? ThemeCatalog::DEFAULT;
        $theme = isset(ThemeCatalog::THEMES[$theme]) ? $theme : ThemeCatalog::DEFAULT;
        $logoVersion = $row['logo_version'] ?? null;

        return [
            'name' => $name,
            'suffix' => $suffix,
            'title' => trim($name.' '.$suffix),
            'theme' => $theme,
            'themeColor' => ThemeCatalog::THEMES[$theme]['colors']['sidebar'],
            'backgroundColor' => ThemeCatalog::THEMES[$theme]['colors']['canvas'],
            'logoUrl' => $logoVersion ? $this->urls->generate('appearance_logo', ['v' => $logoVersion]) : null,
        ];
    }

    public function save(Employee $actor, string $name, string $suffix, string $theme, ?UploadedFile $file, bool $resetLogo): void
    {
        $name = trim($name);
        $suffix = trim($suffix);
        if ($name === '' || mb_strlen($name) > 48 || mb_strlen($suffix) > 12) {
            throw new DomainException('Saisissez un nom de 1 à 48 caractères et une mention courte de 12 caractères maximum.');
        }
        if (!isset(ThemeCatalog::THEMES[$theme])) {
            throw new DomainException('Sélectionnez l’un des thèmes proposés.');
        }
        $values = ['name' => $name, 'suffix' => $suffix, 'theme' => $theme];
        if ($file) {
            $values += $this->validateLogo($file);
        } elseif ($resetLogo) {
            $values += ['logo_content' => null, 'logo_mime' => null, 'logo_version' => null];
        }

        $this->db->transactional(function () use ($values, $actor) {
            $types = isset($values['logo_content']) ? ['logo_content' => ParameterType::BINARY] : [];
            $this->db->update('app_appearance', $values, ['id' => 1], $types);
            $this->audit->audit($actor->id, 'Personnalisation de l’application mise à jour.');
        });
    }

    public function logo(): ?array
    {
        try {
            $row = $this->db->fetchAssociative('SELECT logo_content, logo_mime FROM app_appearance
                WHERE id = 1 AND logo_content IS NOT NULL');
        } catch (TableNotFoundException) {
            return null;
        }
        if (!$row) {
            return null;
        }
        $content = is_resource($row['logo_content']) ? stream_get_contents($row['logo_content']) : $row['logo_content'];

        return ['content' => $content, 'mime' => $row['logo_mime']];
    }

    private function validateLogo(UploadedFile $file): array
    {
        if (!$file->isValid() || !$file->getSize() || $file->getSize() > 2 * 1024 * 1024) {
            throw new DomainException('Ajoutez un logo PNG, JPEG ou WebP de 2 Mo maximum.');
        }
        $content = $file->getContent();
        $info = @getimagesizefromstring($content);
        if (!$info || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new DomainException('Le logo doit être une image PNG, JPEG ou WebP valide.');
        }
        if ($info[0] > 4096 || $info[1] > 4096) {
            throw new DomainException('Le logo doit mesurer au plus 4 096 pixels par côté.');
        }

        return ['logo_content' => $content, 'logo_mime' => $info['mime'], 'logo_version' => hash('sha256', $content)];
    }
}
