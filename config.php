<?php
/**
 * Configuração central. Valores podem ser sobrescritos por variáveis de
 * ambiente ou pelo arquivo .env na raiz do projeto (veja .env.example).
 */
$envFile = __DIR__ . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linha) {
        if (str_starts_with(trim($linha), '#') || !str_contains($linha, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $linha, 2));
        if (getenv($k) === false) putenv("$k=$v");
    }
}

function env(string $k, $padrao = null) {
    $v = getenv($k);
    return ($v === false || $v === '') ? $padrao : $v;
}

define('DB_HOST', env('DB_HOST', '127.0.0.1'));
define('DB_NAME', env('DB_NAME', 'senn_logistica'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));

// IA: provedor externo opcional. Sem chave, o sistema usa os modelos locais (fallback).
define('GEMINI_API_KEY', env('GEMINI_API_KEY', ''));
define('GEMINI_MODEL', env('GEMINI_MODEL', 'gemini-2.5-flash'));

// Depósito (ponto de partida das rotas): SENAI Félix Guisard, Taubaté-SP
define('DEPOSITO_LAT', -23.0262);
define('DEPOSITO_LNG', -45.5559);

define('APP_NOME', 'SENN AI-X Logística');
date_default_timezone_set('America/Sao_Paulo');
