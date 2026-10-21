<?php
require_once __DIR__ . '/../config.php';

/** Conexão PDO única com prepared statements reais (proteção contra SQL Injection). */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function registrar_log(string $acao, ?string $detalhe = null): void {
    q('INSERT INTO logs (usuario_id, acao, detalhe) VALUES (?,?,?)',
      [$_SESSION['usuario']['id'] ?? null, $acao, $detalhe]);
}
