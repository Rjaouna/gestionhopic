<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

foreach (['APP_ENV', 'APP_DEBUG', 'APP_SECRET', 'APP_TIMEZONE', 'DATABASE_URL', 'MAILER_DSN'] as $key) {
    $value = getenv($key, true);
    if ($value !== false) {
        $_ENV[$key] ??= $value;
    }
}
(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
date_default_timezone_set($_SERVER['APP_TIMEZONE'] ?? 'Europe/Paris');

if ($_SERVER['APP_ENV'] === 'prod' && strlen($_SERVER['APP_SECRET'] ?? '') < 32) {
    throw new RuntimeException('Configurez un secret d’application aléatoire pour la production.');
}
