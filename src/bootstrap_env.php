<?php

use Symfony\Component\Dotenv\Dotenv;

if (!class_exists(Dotenv::class)) {
    return; // sécurité, au cas où
}

$dotenv = new Dotenv();

// Répertoire du projet utilisateur
$projectRoot = dirname(__DIR__, 4);

// Charger .env si présent
$envFile = $projectRoot . '/.env';

if (is_file($envFile)) {
    $dotenv->loadEnv($envFile);
}