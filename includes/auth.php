<?php
require_once __DIR__ . '/db.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
// Nenhuma página fica em cache: depois de sair, a seta "voltar" não mostra telas do sistema
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

const PERFIS = [
    'admin' => 'Administrador',
    'gestor' => 'Gestor de frota',
    'despachante' => 'Despachante',
    'motorista' => 'Motorista',
];

/** Escapa saída HTML (proteção contra XSS). */
function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_campo(): string {
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

/** Valida o token CSRF em toda requisição POST. */
function exigir_csrf(): void {
    $t = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(csrf_token(), $t)) {
        http_response_code(419);
        exit('Token de segurança inválido. Recarregue a página.');
    }
}

function usuario(): ?array {
    return $_SESSION['usuario'] ?? null;
}

/** Bloqueia a página para quem não estiver logado ou não tiver um dos perfis informados. */
function exigir_perfil(string ...$perfis): array {
    $u = usuario();
    // encerra a sessão após 30 minutos sem uso
    if ($u && time() - ($_SESSION['ultimo_uso'] ?? time()) > 1800) {
        $_SESSION = [];
        $u = null;
        flash('Sua sessão expirou por inatividade. Entre novamente.', 'info');
    }
    $_SESSION['ultimo_uso'] = time();
    if (!$u) {
        header('Location: ' . url('login.php'));
        exit;
    }
    if ($perfis && $u['perfil'] !== 'admin' && !in_array($u['perfil'], $perfis, true)) {
        http_response_code(403);
        exit('Acesso negado para o seu perfil.');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') exigir_csrf();
    return $u;
}

function url(string $caminho = ''): string {
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    // páginas em subpastas (api/) apontam para a raiz do projeto
    if (str_ends_with($base, '/api')) $base = substr($base, 0, -4);
    return $base . '/' . ltrim($caminho, '/');
}

function painel_do_perfil(string $perfil): string {
    return match ($perfil) {
        'motorista' => 'motorista.php',
        'despachante' => 'pedidos.php',
        default => 'dashboard.php',
    };
}

function flash(?string $msg = null, string $tipo = 'ok') {
    if ($msg !== null) { $_SESSION['flash'] = [$msg, $tipo]; return null; }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}
