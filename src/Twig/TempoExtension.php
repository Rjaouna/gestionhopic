<?php

namespace App\Twig;

use App\Service\AppearanceSettings;
use App\Service\ThemeCatalog;
use DateTimeImmutable;
use DateTimeInterface;
use IntlDateFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class TempoExtension extends AbstractExtension
{
    public function __construct(private readonly AppearanceSettings $appearance)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('tempo_date', $this->date(...)),
            new TwigFunction('tempo_days', $this->days(...)),
            new TwigFunction('initials', $this->initials(...)),
            new TwigFunction('application_appearance', $this->appearance->current(...)),
            new TwigFunction('application_themes', static fn (): array => ThemeCatalog::THEMES),
        ];
    }

    public function date(string|DateTimeInterface $date, string $pattern = 'd MMM'): string
    {
        $date = is_string($date) ? new DateTimeImmutable($date) : $date;
        $formatter = new IntlDateFormatter('fr_FR', timezone: $date->getTimezone(), pattern: $pattern);

        return rtrim($formatter->format($date), '.');
    }

    public function days(int $units): string
    {
        return number_format($units / 2, $units % 2 ? 1 : 0, ',', ' ');
    }

    public function initials(string $first, string $last): string
    {
        return mb_strtoupper(mb_substr($first, 0, 1).mb_substr($last, 0, 1));
    }
}
