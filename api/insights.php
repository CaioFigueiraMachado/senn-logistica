<?php
/** RF12 - Gera a análise inteligente em JSON para os cartões da tela de IA. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ia.php';
$u = exigir_perfil('gestor');
header('Content-Type: application/json; charset=utf-8');

$dias = in_array((int)($_GET['dias'] ?? 30), [7, 30, 90], true) ? (int)$_GET['dias'] : 30;
$a = gerar_insights($dias);

// Classifica cada insight para exibição em cartões
$cartoes = array_map(function ($t) {
    $l = mb_strtolower($t);
    [$tipo, $rotulo] = match (true) {
        str_contains($l, 'gargalo') || str_contains($l, 'atraso') || str_contains($l, 'falha') => ['gargalo', 'Gargalo'],
        str_contains($l, 'custo') || str_contains($l, 'r$') => ['custo', 'Custo'],
        str_contains($l, 'previs') || str_contains($l, 'dimension') || str_contains($l, 'manutenção') => ['previsao', 'Capacidade'],
        default => ['recomendacao', 'Recomendação'],
    };
    return ['tipo' => $tipo, 'rotulo' => $rotulo, 'texto' => $t];
}, $a['insights']);

registrar_log('analise_ia', $a['fonte']);
echo json_encode(['fonte' => $a['fonte'], 'status' => ia_status(), 'cartoes' => $cartoes, 'dados' => $a['dados'], 'gerado' => date('d/m/Y H:i')], JSON_UNESCAPED_UNICODE);
